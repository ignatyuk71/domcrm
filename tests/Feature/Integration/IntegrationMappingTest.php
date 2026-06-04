<?php

namespace Tests\Feature\Integration;

use App\Models\ExternalProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationMappingTest extends TestCase
{
    use RefreshDatabase;

    protected function operator(): User
    {
        return User::factory()->create(['role' => User::ROLE_OPERATOR, 'is_active' => true]);
    }

    protected function source(): OrderSource
    {
        return OrderSource::create([
            'code' => 'site-' . uniqid(), 'name' => 'Site', 'type' => 'order', 'sort_order' => 0,
            'is_default' => false, 'is_integration' => true, 'mode' => 'push', 'adapter' => 'custom',
            'api_key' => 'dkey_' . uniqid(), 'is_enabled' => true,
        ]);
    }

    protected function variant(string $sku = 'VAR-1', string $size = '40'): ProductVariant
    {
        $product = Product::create([
            'title' => 'Тапочки', 'sku' => 'P-' . $sku, 'currency' => 'UAH',
            'cost_price' => 0, 'sale_price' => 500, 'stock_qty' => 0, 'min_stock' => 0, 'is_active' => true,
        ]);

        return ProductVariant::create([
            'product_id' => $product->id, 'size' => $size, 'sku' => $sku, 'stock_qty' => 5, 'is_active' => true,
        ]);
    }

    protected function unmatchedOrder(OrderSource $source, string $externalId = 'EX1', string $size = '40'): Order
    {
        $order = Order::create([
            'order_number' => (string) uniqid(),
            'source' => $source->code, 'source_id' => $source->id,
            'status' => 'new', 'payment_status' => 'unpaid', 'currency' => 'UAH',
            'needs_review' => true,
        ]);
        $order->items()->create([
            'product_id' => null, 'product_variant_id' => null, 'external_id' => $externalId,
            'product_title' => 'Невідоме', 'sku' => 'SITE-SKU', 'size' => $size, 'price' => 300, 'qty' => 1, 'total' => 300,
        ]);

        return $order;
    }

    public function test_guest_cannot_access_review_screen(): void
    {
        $this->get('/integrations/review')->assertRedirect(route('login'));
    }

    public function test_packer_is_forbidden(): void
    {
        $packer = User::factory()->create(['role' => User::ROLE_PACKER, 'is_active' => true]);
        $this->actingAs($packer)->get('/integrations/review')->assertStatus(403);
    }

    public function test_operator_can_map_item_and_clears_review(): void
    {
        $source = $this->source();
        $variant = $this->variant('6027-36-37', '40');
        $order = $this->unmatchedOrder($source, 'EX1', '40');
        $item = $order->items()->first();

        $this->actingAs($this->operator())
            ->post('/integrations/review/map', [
                'order_item_id' => $item->id,
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'remember' => 1,
            ])
            ->assertRedirect();

        $item->refresh();
        $this->assertSame($variant->id, $item->product_variant_id);
        $this->assertSame($variant->product_id, $item->product_id);
        $this->assertFalse($order->fresh()->needs_review);

        // Пам'ять збережено.
        $this->assertDatabaseHas('external_products', [
            'source_id' => $source->id,
            'external_id' => 'EX1',
            'external_size' => '40',
            'product_variant_id' => $variant->id,
        ]);
    }

    public function test_mapping_applies_to_sibling_orders_of_same_source(): void
    {
        $source = $this->source();
        $variant = $this->variant('V', '40');
        $orderA = $this->unmatchedOrder($source, 'EXX', '40');
        $orderB = $this->unmatchedOrder($source, 'EXX', '40');
        $itemA = $orderA->items()->first();

        $this->actingAs($this->operator())->post('/integrations/review/map', [
            'order_item_id' => $itemA->id,
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->id,
            'remember' => 1,
        ])->assertRedirect();

        // Обидва замовлення розшиті одним зіставленням.
        $this->assertSame($variant->id, $orderB->items()->first()->product_variant_id);
        $this->assertFalse($orderA->fresh()->needs_review);
        $this->assertFalse($orderB->fresh()->needs_review);
    }

    public function test_needs_review_stays_when_other_items_unmatched(): void
    {
        $source = $this->source();
        $variant = $this->variant('V2', '41');
        $order = $this->unmatchedOrder($source, 'E-A', '41');
        // друга нерозпізнана позиція в тому ж замовленні
        $order->items()->create([
            'product_id' => null, 'product_variant_id' => null, 'external_id' => 'E-B',
            'product_title' => 'Інше невідоме', 'sku' => 'X', 'size' => '42', 'price' => 100, 'qty' => 1, 'total' => 100,
        ]);
        $firstItem = $order->items()->where('external_id', 'E-A')->first();

        $this->actingAs($this->operator())->post('/integrations/review/map', [
            'order_item_id' => $firstItem->id,
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->id,
        ])->assertRedirect();

        $this->assertTrue($order->fresh()->needs_review); // бо лишилась E-B
    }

    public function test_rejects_variant_not_belonging_to_product(): void
    {
        $source = $this->source();
        $v1 = $this->variant('A', '40');
        $v2 = $this->variant('B', '41'); // інший товар
        $order = $this->unmatchedOrder($source, 'EY', '40');
        $item = $order->items()->first();

        $this->actingAs($this->operator())->post('/integrations/review/map', [
            'order_item_id' => $item->id,
            'product_id' => $v1->product_id,
            'product_variant_id' => $v2->id, // не належить v1->product
        ])->assertSessionHas('error');

        $this->assertNull($item->fresh()->product_id); // не змінилось
    }
}
