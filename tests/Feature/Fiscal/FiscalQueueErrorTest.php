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
 * Перевірка, що черга НЕ позначає success, коли чек реально впав.
 * (Окремий клас — щоб мокнути receipts/sell як ПОМИЛКУ, без success-стабу із setUp.)
 */
class FiscalQueueErrorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CheckboxSetting::create([
            'api_url' => 'https://api.checkbox.in.ua/api/v1',
            'license_key' => 'L', 'login' => 'l', 'password' => 'p', 'enabled' => true,
        ]);
        Status::create(['code' => 'delivered_paid', 'name' => 'Доставлено й оплачено', 'type' => 'order']);
    }

    /** Кожен тест задає свою відповідь Checkbox на receipts/sell. */
    private function fakeCheckbox(array $sellResponse, int $sellStatus = 200): void
    {
        Http::fake([
            '*cashier/signin' => Http::response(['access_token' => 't'], 200),
            '*cashier/shift' => Http::response(['status' => 'OPENED'], 200),
            '*receipts/sell' => Http::response($sellResponse, $sellStatus),
        ]);
    }

    private function makeQueuedOrder(): FiscalQueue
    {
        $product = Product::create(['title' => 'Капці', 'sku' => 'E' . uniqid(), 'sale_price' => 500, 'currency' => 'UAH', 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'size' => '38', 'sku' => 'EV-' . uniqid(), 'stock_qty' => 5, 'is_active' => true]);
        $order = Order::create(['order_number' => 'E-' . uniqid(), 'status' => 'new', 'payment_status' => 'unpaid', 'currency' => 'UAH']);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id,
            'product_title' => 'Капці', 'sku' => 'E1-38', 'size' => '38', 'price' => 500, 'qty' => 1, 'total' => 500,
        ]);

        return FiscalQueue::create([
            'order_id' => $order->id, 'type' => FiscalReceipt::TYPE_SELL, 'amount_cents' => 50000,
            'status' => FiscalQueue::STATUS_WAITING, 'available_at' => now()->subHour(),
        ]);
    }

    public function test_marks_error_not_success_when_receipt_fails(): void
    {
        $this->fakeCheckbox(['message' => 'Помилка Checkbox', 'code' => 'x'], 400); // чек падає з винятком
        $item = $this->makeQueuedOrder();

        app(FiscalQueueService::class)->processAvailable();

        $fresh = $item->fresh();
        $this->assertSame(FiscalQueue::STATUS_ERROR, $fresh->status);
        $this->assertSame(1, $fresh->attempts);
        $this->assertNotEmpty($fresh->last_error);
        $this->assertSame('unpaid', Order::find($fresh->order_id)->payment_status);
    }

    /** Тиха гілка: Checkbox 200, але статус не DONE → чек error, джоба БЕЗ винятку. */
    public function test_marks_error_when_job_returns_no_success_receipt(): void
    {
        $this->fakeCheckbox(['id' => 'uuid-pending', 'status' => 'PENDING'], 200); // 200, але не success
        $item = $this->makeQueuedOrder();

        app(FiscalQueueService::class)->processAvailable();

        // Раніше тут ставився фальшивий SUCCESS — тепер ERROR (бо успішного чека немає).
        $fresh = $item->fresh();
        $this->assertSame(FiscalQueue::STATUS_ERROR, $fresh->status);
        $this->assertSame(1, $fresh->attempts);
    }
}
