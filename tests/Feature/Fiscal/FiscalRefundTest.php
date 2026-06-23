<?php

namespace Tests\Feature\Fiscal;

use App\Models\CheckboxSetting;
use App\Models\FiscalReceipt;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Повернення (refund): б'є на суму ФАКТИЧНО проданого мінус уже повернуте,
 * а не на повну суму замовлення.
 */
class FiscalRefundTest extends TestCase
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

        Http::fake([
            '*cashier/signin' => Http::response(['access_token' => 'test-token'], 200),
            '*cashier/shift' => Http::response(['status' => 'OPENED'], 200),
            // Повернення йде на той самий receipts/sell (з is_return:true), не на окремий шлях.
            '*receipts/sell' => Http::response(['id' => 'uuid-ret-1', 'status' => 'DONE', 'fiscal_code' => 'FC-RET'], 200),
        ]);
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => User::ROLE_OWNER, 'is_active' => true]);
    }

    private function makeOrder(): Order
    {
        $product = Product::create(['title' => 'Капці', 'sku' => 'R1', 'sale_price' => 500, 'currency' => 'UAH', 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'size' => '38-39', 'sku' => 'R1-38', 'stock_qty' => 5, 'is_active' => true]);
        $order = Order::create(['order_number' => 'R-' . uniqid(), 'status' => 'new', 'payment_status' => 'paid', 'currency' => 'UAH']);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id,
            'product_title' => 'Капці', 'sku' => 'R1-38', 'size' => '38-39', 'price' => 500, 'qty' => 1, 'total' => 500,
        ]);
        return $order->fresh();
    }

    public function test_refund_returns_sold_amount(): void
    {
        $order = $this->makeOrder();
        // Продано на всю суму (успішний SELL-чек).
        $order->fiscalReceipts()->create([
            'type' => FiscalReceipt::TYPE_SELL, 'status' => FiscalReceipt::STATUS_SUCCESS,
            'total_amount' => 50000, 'uuid' => 'sell-uuid-1', 'payload_hash' => 's',
        ]);

        $this->actingAs($this->owner())
            ->postJson("/api/orders/{$order->id}/refund")
            ->assertOk();

        // Створено успішний RETURN-чек, у Checkbox пішов запит на повернення.
        $this->assertSame(1, $order->fiscalReceipts()
            ->where('type', FiscalReceipt::TYPE_RETURN)
            ->where('status', FiscalReceipt::STATUS_SUCCESS)->count());
        // Запит пішов на receipts/sell і саме як ПОВЕРНЕННЯ (is_return:true).
        Http::assertSent(function ($r) {
            return str_contains($r->url(), 'receipts/sell')
                && collect($r->data()['goods'] ?? [])->contains(fn ($g) => ($g['is_return'] ?? false) === true);
        });
    }

    public function test_refund_rejected_when_nothing_sold(): void
    {
        $order = $this->makeOrder(); // без жодного SELL-чека

        $this->actingAs($this->owner())
            ->postJson("/api/orders/{$order->id}/refund")
            ->assertStatus(422);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'receipts/sell'));
    }

    public function test_status_reflects_full_refund(): void
    {
        $order = $this->makeOrder();
        $order->fiscalReceipts()->create([
            'type' => FiscalReceipt::TYPE_SELL, 'status' => FiscalReceipt::STATUS_SUCCESS,
            'total_amount' => 50000, 'uuid' => 's-uuid', 'payload_hash' => 's',
        ]);
        $order->fiscalReceipts()->create([
            'type' => FiscalReceipt::TYPE_RETURN, 'status' => FiscalReceipt::STATUS_SUCCESS,
            'total_amount' => 50000, 'uuid' => 'r-uuid', 'payload_hash' => 'r',
        ]);

        $this->actingAs($this->owner())
            ->getJson("/api/orders/{$order->id}/fiscal-status")
            ->assertOk()
            ->assertJsonPath('has_refund', true)
            ->assertJsonPath('refunded_cents', 50000)
            ->assertJsonPath('already_paid_cents', 0)   // чиста сума: продаж − повернення
            ->assertJsonPath('status', 'refunded');
    }

    public function test_status_reflects_partial_refund(): void
    {
        $order = $this->makeOrder();
        $order->fiscalReceipts()->create([
            'type' => FiscalReceipt::TYPE_SELL, 'status' => FiscalReceipt::STATUS_SUCCESS,
            'total_amount' => 50000, 'uuid' => 's-uuid2', 'payload_hash' => 's',
        ]);
        $order->fiscalReceipts()->create([
            'type' => FiscalReceipt::TYPE_RETURN, 'status' => FiscalReceipt::STATUS_SUCCESS,
            'total_amount' => 20000, 'uuid' => 'r-uuid2', 'payload_hash' => 'r',
        ]);

        $this->actingAs($this->owner())
            ->getJson("/api/orders/{$order->id}/fiscal-status")
            ->assertOk()
            ->assertJsonPath('has_refund', true)
            ->assertJsonPath('refunded_cents', 20000)
            ->assertJsonPath('already_paid_cents', 30000); // 500 − 200
    }

    public function test_refund_rejected_when_already_fully_refunded(): void
    {
        $order = $this->makeOrder();
        $order->fiscalReceipts()->create([
            'type' => FiscalReceipt::TYPE_SELL, 'status' => FiscalReceipt::STATUS_SUCCESS,
            'total_amount' => 50000, 'uuid' => 'sell-uuid-2', 'payload_hash' => 's',
        ]);
        $order->fiscalReceipts()->create([
            'type' => FiscalReceipt::TYPE_RETURN, 'status' => FiscalReceipt::STATUS_SUCCESS,
            'total_amount' => 50000, 'uuid' => 'ret-uuid-2', 'payload_hash' => 'r',
        ]);

        $this->actingAs($this->owner())
            ->postJson("/api/orders/{$order->id}/refund")
            ->assertStatus(422);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'receipts/sell'));
    }
}
