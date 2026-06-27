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
        $uuid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'; // UUID попередньої спроби

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
        $uuid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'; // UUID попередньої спроби

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

    /** not_found: перевикористовуємо рядок попередньої спроби (без дубля uuid). */
    public function test_reuses_prior_receipt_row_when_not_found(): void
    {
        $order = $this->makeOrder(500.00, 1);
        $uuid = 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff';
        $prior = $order->fiscalReceipts()->create([
            'type' => FiscalReceipt::TYPE_SELL,
            'status' => FiscalReceipt::STATUS_ERROR,
            'total_amount' => 50000,
            'uuid' => $uuid,
            'payload_hash' => 'prior',
        ]);

        Http::fake([
            '*cashier/signin' => Http::response(['access_token' => 'test-token'], 200),
            '*cashier/shift' => Http::response(['status' => 'OPENED'], 200),
            "*receipts/{$uuid}" => Http::response('not found', 404), // GET → not_found
            '*receipts/sell' => Http::response(['id' => $uuid, 'status' => 'DONE', 'fiscal_code' => 'FC-NEW'], 200),
        ]);

        (new FiscalizeOrderJob($order))->handle(app(CheckboxService::class));

        Http::assertSent(fn ($r) => str_contains($r->url(), 'receipts/sell'));
        // Рядок ОДИН (перевикористали prior, без дубля uuid), став success.
        $this->assertSame(1, FiscalReceipt::where('order_id', $order->id)->count());
        $this->assertSame(FiscalReceipt::STATUS_SUCCESS, $prior->fresh()->status);
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

    /**
     * ПЕРЕДОПЛАТА + ЗАЛИШОК: після успішного чека передоплати другий, законний
     * чек на залишок мусить пробитися ОКРЕМО. (Раніше ідемпотентність помилково
     * «підхоплювала» чек передоплати й залишок ніколи не проходив.)
     */
    public function test_prepayment_then_remainder_creates_second_receipt(): void
    {
        $order = $this->makeOrder(1000.00, 1);

        // Чек передоплати: успішний sell на 100 грн із власним uuid (як у проді).
        $order->fiscalReceipts()->create([
            'type' => FiscalReceipt::TYPE_SELL,
            'status' => FiscalReceipt::STATUS_SUCCESS,
            'total_amount' => 10000,
            'uuid' => 'aaaaprep-0000-4000-8000-000000000001',
            'payload_hash' => 'prepay',
        ]);

        // Фіскалізуємо ЗАЛИШОК 900 грн (1000 - 100).
        (new FiscalizeOrderJob($order->fresh(), FiscalReceipt::TYPE_SELL, 90000))
            ->handle(app(CheckboxService::class));

        // Другий чек РЕАЛЬНО пішов у Checkbox (а не «підхопили» передоплату).
        Http::assertSent(fn ($r) => str_contains($r->url(), 'receipts/sell'));

        // Тепер 2 успішні sell-чеки на загалом 1000 грн, замовлення оплачене.
        $sells = FiscalReceipt::where('order_id', $order->id)
            ->where('type', FiscalReceipt::TYPE_SELL)
            ->where('status', FiscalReceipt::STATUS_SUCCESS)
            ->get();
        $this->assertSame(2, $sells->count());
        $this->assertSame(100000, (int) $sells->sum('total_amount'));
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    /**
     * ЗАХИСТ СУМ зберігається: навіть після передоплати не можна пробити більше
     * за залишок (спроба пробити повну суму поверх передоплати — відмова).
     */
    public function test_cannot_overshoot_after_prepayment(): void
    {
        $order = $this->makeOrder(1000.00, 1);

        $order->fiscalReceipts()->create([
            'type' => FiscalReceipt::TYPE_SELL,
            'status' => FiscalReceipt::STATUS_SUCCESS,
            'total_amount' => 10000,
            'uuid' => 'aaaaprep-0000-4000-8000-000000000002',
            'payload_hash' => 'prepay',
        ]);

        // Спроба пробити ще раз ПОВНУ суму (1000) поверх передоплати — має відмовити.
        (new FiscalizeOrderJob($order->fresh(), FiscalReceipt::TYPE_SELL, 100000))
            ->handle(app(CheckboxService::class));

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'receipts/sell'));
        $this->assertSame(1, FiscalReceipt::where('order_id', $order->id)
            ->where('status', FiscalReceipt::STATUS_SUCCESS)->count());
    }
}
