<?php

namespace Tests\Feature\Fiscal;

use App\Jobs\FiscalizeOrderJob;
use App\Models\CheckboxSetting;
use App\Models\FiscalReceipt;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CheckboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Захисний пояс для грошового флоу фіскалізації (Checkbox/РРО).
 * Усі виклики Checkbox API замокані — жодного реального чека.
 */
class FiscalizeOrderJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CheckboxSetting::create([
            'api_url' => 'https://api.checkbox.in.ua/api/v1',
            'license_key' => 'TEST-LICENSE',
            'login' => 'test-login',
            'password' => 'test-password',
            'enabled' => true,
        ]);

        // Статус, у який Job переводить повністю оплачене замовлення.
        \App\Models\Status::create([
            'code' => 'delivered_paid',
            'name' => 'Доставлено й оплачено',
            'type' => 'order',
        ]);

        Http::fake([
            '*cashier/signin' => Http::response(['access_token' => 'test-token'], 200),
            '*cashier/shift' => Http::response(['status' => 'OPENED'], 200),
            '*shifts*' => Http::response(['status' => 'OPENED'], 200),
            '*receipts/sell' => Http::response([
                'id' => 'uuid-sell-1',
                'status' => 'DONE',
                'fiscal_code' => 'FC-123',
                'tax_url' => 'https://check.checkbox.ua/uuid-sell-1',
            ], 200),
            '*receipts/return' => Http::response([
                'id' => 'uuid-return-1',
                'status' => 'DONE',
                'fiscal_code' => 'FC-456',
            ], 200),
        ]);
    }

    /** Створює замовлення з одним рядком (qty, lineTotal у гривнях). */
    private function makeOrder(float $lineTotal, int $qty): Order
    {
        $product = Product::create([
            'title' => 'Тестові капці',
            'sku' => 'TST-1',
            'sale_price' => $lineTotal,
            'currency' => 'UAH',
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'size' => '38-39',
            'sku' => 'TST-1-38-39',
            'stock_qty' => 10,
            'is_active' => true,
        ]);

        $order = Order::create([
            'order_number' => 'T-' . uniqid(),
            'status' => 'new',
            'payment_status' => 'unpaid',
            'currency' => 'UAH',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'product_title' => $product->title,
            'sku' => $variant->sku,
            'size' => $variant->size,
            'price' => $lineTotal / $qty,
            'qty' => $qty,
            'total' => $lineTotal,
        ]);

        return $order->fresh();
    }

    /**
     * ІНВАРІАНТ: сума оплати (payments.value), яку шлемо в Checkbox, ЗАВЖДИ
     * дорівнює сумі товарів (price*qty/1000). Складний кейс: дробова ціна за
     * одиницю (10.00 / 3 = 3.3333) — округлення не має ламати рівність.
     */
    public function test_payment_value_always_equals_goods_sum(): void
    {
        $order = $this->makeOrder(10.00, 3);

        (new FiscalizeOrderJob($order))->handle(app(CheckboxService::class));

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), 'receipts/sell')) {
                return false;
            }
            $body = $request->data();
            $goodsSum = 0;
            foreach ($body['goods'] as $g) {
                $goodsSum += (int) round(($g['good']['price'] * $g['quantity']) / 1000);
            }

            return $body['payments'][0]['value'] === $goodsSum;
        });
    }

    /** Happy-path: успішний чек створюється, замовлення стає оплаченим. */
    public function test_creates_success_receipt_and_marks_order_paid(): void
    {
        $order = $this->makeOrder(500.00, 1);

        (new FiscalizeOrderJob($order))->handle(app(CheckboxService::class));

        $receipt = FiscalReceipt::where('order_id', $order->id)->first();
        $this->assertNotNull($receipt);
        $this->assertSame(FiscalReceipt::STATUS_SUCCESS, $receipt->status);
        $this->assertSame(FiscalReceipt::TYPE_SELL, $receipt->type);
        $this->assertSame(50000, (int) $receipt->total_amount); // 500.00 грн = 50000 коп
        $this->assertSame('uuid-sell-1', $receipt->uuid);

        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    /** Якщо статусу delivered_paid немає — чек НЕ падає на FK, лишається успішним. */
    public function test_missing_status_does_not_break_successful_receipt(): void
    {
        \App\Models\Status::where('code', 'delivered_paid')->delete();
        $order = $this->makeOrder(500.00, 1);

        (new FiscalizeOrderJob($order))->handle(app(CheckboxService::class));

        $receipt = FiscalReceipt::where('order_id', $order->id)->first();
        $this->assertSame(FiscalReceipt::STATUS_SUCCESS, $receipt->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    /** На створення передаємо клієнтський UUID чека (основа ідемпотентності). */
    public function test_sends_client_receipt_id_to_checkbox(): void
    {
        $order = $this->makeOrder(500.00, 1);

        (new FiscalizeOrderJob($order))->handle(app(CheckboxService::class));

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), 'receipts/sell')) {
                return false;
            }
            return !empty($request->data()['id']); // наш UUID присутній
        });
    }

    /** Якщо чек із нашим UUID уже існує в Checkbox — не створюємо вдруге. */
    public function test_does_not_recreate_when_receipt_already_exists(): void
    {
        $order = $this->makeOrder(500.00, 1);
        $uuid = \Ramsey\Uuid\Uuid::uuid5(
            \Ramsey\Uuid\Uuid::NAMESPACE_URL,
            "domcrm-receipt-{$order->id}-" . FiscalReceipt::TYPE_SELL . '-50000'
        )->toString();

        // Попередня невдала спроба з тим самим UUID (чек насправді створився в Checkbox).
        $order->fiscalReceipts()->create([
            'type' => FiscalReceipt::TYPE_SELL,
            'status' => FiscalReceipt::STATUS_ERROR,
            'total_amount' => 50000,
            'uuid' => $uuid,
            'payload_hash' => 'prior',
        ]);

        Http::fake([
            '*cashier/signin' => Http::response(['access_token' => 'test-token'], 200),
            '*cashier/shift' => Http::response(['status' => 'OPENED'], 200),
            "*receipts/{$uuid}" => Http::response(['id' => $uuid, 'status' => 'DONE', 'fiscal_code' => 'FC-EXIST'], 200),
            '*receipts/sell' => Http::response(['id' => 'SHOULD-NOT-BE-USED', 'status' => 'DONE'], 200),
        ]);

        (new FiscalizeOrderJob($order))->handle(app(CheckboxService::class));

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'receipts/sell'));
        $this->assertSame(1, FiscalReceipt::where('order_id', $order->id)
            ->where('status', FiscalReceipt::STATUS_SUCCESS)->count());
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    /** Якщо статус чека невідомий (мережа) — НЕ створюємо, щоб не дублювати. */
    public function test_aborts_without_create_when_status_unknown(): void
    {
        $order = $this->makeOrder(500.00, 1);
        $uuid = \Ramsey\Uuid\Uuid::uuid5(
            \Ramsey\Uuid\Uuid::NAMESPACE_URL,
            "domcrm-receipt-{$order->id}-" . FiscalReceipt::TYPE_SELL . '-50000'
        )->toString();

        $order->fiscalReceipts()->create([
            'type' => FiscalReceipt::TYPE_SELL,
            'status' => FiscalReceipt::STATUS_ERROR,
            'total_amount' => 50000,
            'uuid' => $uuid,
            'payload_hash' => 'prior',
        ]);

        Http::fake([
            '*cashier/signin' => Http::response(['access_token' => 'test-token'], 200),
            "*receipts/{$uuid}" => Http::response('server error', 500),
            '*receipts/sell' => Http::response(['id' => 'X', 'status' => 'DONE'], 200),
        ]);

        try {
            (new FiscalizeOrderJob($order))->handle(app(CheckboxService::class));
        } catch (\Throwable $e) {
            // очікувано: кидає, щоб ретрайнути пізніше
        }

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'receipts/sell'));
    }

    /** Ідемпотентність: повторний запуск не створює другий успішний чек. */
    public function test_does_not_fiscalize_twice(): void
    {
        $order = $this->makeOrder(500.00, 1);
        $job = fn () => (new FiscalizeOrderJob($order->fresh()))->handle(app(CheckboxService::class));

        $job();
        $job();

        $this->assertSame(
            1,
            FiscalReceipt::where('order_id', $order->id)
                ->where('type', FiscalReceipt::TYPE_SELL)
                ->where('status', FiscalReceipt::STATUS_SUCCESS)
                ->count()
        );
    }
}
