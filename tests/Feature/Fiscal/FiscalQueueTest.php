<?php

namespace Tests\Feature\Fiscal;

use App\Models\CheckboxSetting;
use App\Models\FiscalQueue;
use App\Models\FiscalReceipt;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Status;
use App\Services\FiscalQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Авторетрай черги фіскалізації: помилкові елементи з невичерпаними спробами
 * повторюються; уже оплачені — пропускаються; вичерпані — лишаються в ERROR.
 */
class FiscalQueueTest extends TestCase
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
        Status::create(['code' => 'delivered_paid', 'name' => 'Доставлено й оплачено', 'type' => 'order']);

        Http::fake([
            '*cashier/signin' => Http::response(['access_token' => 'test-token'], 200),
            '*cashier/shift' => Http::response(['status' => 'OPENED'], 200),
            '*receipts/sell' => Http::response(['id' => 'uuid-sell-q', 'status' => 'DONE', 'fiscal_code' => 'FC-Q'], 200),
        ]);
    }

    private function makeOrder(): Order
    {
        $product = Product::create(['title' => 'Капці', 'sku' => 'Q1', 'sale_price' => 500, 'currency' => 'UAH', 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'size' => '38-39', 'sku' => 'Q1-38', 'stock_qty' => 5, 'is_active' => true]);
        $order = Order::create(['order_number' => 'Q-' . uniqid(), 'status' => 'new', 'payment_status' => 'unpaid', 'currency' => 'UAH']);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id,
            'product_title' => 'Капці', 'sku' => 'Q1-38', 'size' => '38-39', 'price' => 500, 'qty' => 1, 'total' => 500,
        ]);
        return $order->fresh();
    }

    public function test_retries_errored_item_with_attempts_left(): void
    {
        $order = $this->makeOrder();
        $item = FiscalQueue::create([
            'order_id' => $order->id, 'type' => FiscalReceipt::TYPE_SELL, 'amount_cents' => 50000,
            'status' => FiscalQueue::STATUS_ERROR, 'attempts' => 1, 'available_at' => now()->subHour(),
        ]);

        app(FiscalQueueService::class)->processAvailable();

        $this->assertSame(FiscalQueue::STATUS_SUCCESS, $item->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_skips_errored_item_when_already_paid(): void
    {
        $order = $this->makeOrder();
        // Замовлення вже оплачене (успішний чек на всю суму).
        $order->fiscalReceipts()->create([
            'type' => FiscalReceipt::TYPE_SELL, 'status' => FiscalReceipt::STATUS_SUCCESS,
            'total_amount' => 50000, 'uuid' => 'paid-uuid-1', 'payload_hash' => 'x',
        ]);
        $item = FiscalQueue::create([
            'order_id' => $order->id, 'type' => FiscalReceipt::TYPE_SELL, 'amount_cents' => 50000,
            'status' => FiscalQueue::STATUS_ERROR, 'attempts' => 1, 'available_at' => now()->subHour(),
        ]);

        app(FiscalQueueService::class)->processAvailable();

        $this->assertSame(FiscalQueue::STATUS_SKIPPED, $item->fresh()->status);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'receipts/sell'));
    }

    public function test_does_not_retry_after_max_attempts(): void
    {
        $order = $this->makeOrder();
        $item = FiscalQueue::create([
            'order_id' => $order->id, 'type' => FiscalReceipt::TYPE_SELL, 'amount_cents' => 50000,
            'status' => FiscalQueue::STATUS_ERROR, 'attempts' => FiscalQueueService::MAX_ATTEMPTS,
            'available_at' => now()->subHour(),
        ]);

        app(FiscalQueueService::class)->processAvailable();

        // Вичерпані спроби — елемент не чіпаємо, чек не шлемо.
        $this->assertSame(FiscalQueue::STATUS_ERROR, $item->fresh()->status);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'receipts/sell'));
    }
}
