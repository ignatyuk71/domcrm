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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Крон зміни не б'є чеки, поки зміна реально не OPENED (а не «відкривається») —
 * інакше перша спроба гарантовано падає «Зміну не відкрито».
 */
class ManageFiscalShiftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Полудень — гарантовано у вікні 08:00–22:00 і після часу черги 08:30.
        Carbon::setTestNow(Carbon::parse('2026-06-23 12:00:00'));

        CheckboxSetting::create([
            'api_url' => 'https://api.checkbox.in.ua/api/v1',
            'license_key' => 'L', 'login' => 'l', 'password' => 'p',
            'enabled' => true, 'queue_enabled' => true,
            'open_time' => '08:00', 'close_time' => '22:00', 'queue_process_time' => '08:30',
            'last_opened_at' => Carbon::parse('2026-06-23 08:00:00'), // вже відкрито сьогодні → крон не відкриває повторно
        ]);
        Status::create(['code' => 'delivered_paid', 'name' => 'Доставлено й оплачено', 'type' => 'order']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeQueuedOrder(): FiscalQueue
    {
        $product = Product::create(['title' => 'Капці', 'sku' => 'M1', 'sale_price' => 500, 'currency' => 'UAH', 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'size' => '38', 'sku' => 'M1-38', 'stock_qty' => 5, 'is_active' => true]);
        $order = Order::create(['order_number' => 'M-' . uniqid(), 'status' => 'new', 'payment_status' => 'unpaid', 'currency' => 'UAH']);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id,
            'product_title' => 'Капці', 'sku' => 'M1-38', 'size' => '38', 'price' => 500, 'qty' => 1, 'total' => 500,
        ]);

        return FiscalQueue::create([
            'order_id' => $order->id, 'type' => FiscalReceipt::TYPE_SELL, 'amount_cents' => 50000,
            'status' => FiscalQueue::STATUS_WAITING, 'available_at' => now()->subHour(),
        ]);
    }

    public function test_queue_skipped_when_shift_not_yet_opened(): void
    {
        $item = $this->makeQueuedOrder();

        Http::fake([
            '*cashier/signin' => Http::response(['access_token' => 't'], 200),
            '*cashier/shift' => Http::response(['status' => 'CREATED'], 200), // зміна ще «відкривається»
        ]);

        $this->artisan('fiscal:shift-manager')->assertExitCode(0);

        // Чек НЕ б'ється, елемент лишається в черзі до наступного тіку.
        $this->assertSame(FiscalQueue::STATUS_WAITING, $item->fresh()->status);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'receipts/sell'));
    }

    public function test_queue_processed_when_shift_opened(): void
    {
        $item = $this->makeQueuedOrder();

        Http::fake([
            '*cashier/signin' => Http::response(['access_token' => 't'], 200),
            '*cashier/shift' => Http::response(['status' => 'OPENED'], 200),
            '*receipts/sell' => Http::response(['id' => 'uuid-m', 'status' => 'DONE', 'fiscal_code' => 'FC-M'], 200),
        ]);

        $this->artisan('fiscal:shift-manager')->assertExitCode(0);

        $this->assertSame(FiscalQueue::STATUS_SUCCESS, $item->fresh()->status);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'receipts/sell'));
    }
}
