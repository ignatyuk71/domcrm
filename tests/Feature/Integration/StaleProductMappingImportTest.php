<?php

namespace Tests\Feature\Integration;

use App\Models\ExternalOrderRaw;
use App\Models\ExternalProduct;
use App\Models\Order;
use App\Models\OrderSource;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StaleProductMappingImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Без зовнішньої транзакції відтворюємо пошкоджені зв'язки, потім повертаємо перевірку FK.
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_intake_repairs_deleted_variant_from_current_sku(): void
    {
        [$source, $product, $old, $map] = $this->fixture();
        Schema::withoutForeignKeyConstraints(fn () => $old->delete());
        $current = $this->variant($product);
        $this->assertSame($old->id, $map->fresh()->product_variant_id);

        $response = $this->send($source)->assertStatus(202)
            ->assertJsonPath('status', 'processed')->assertJsonPath('needs_review', false);
        $order = Order::findOrFail($response->json('order_id'));

        $this->assertSame($current->id, $order->items()->first()->product_variant_id);
        $this->assertSame($product->id, $order->items()->first()->product_id);
        $this->assertSame($current->id, $map->fresh()->product_variant_id);
        $this->assertSame($order->id, ExternalOrderRaw::where('external_order_id', 'WEB-STALE')->first()->order_id);
    }

    public function test_intake_keeps_unmatched_order_when_mapped_product_was_deleted(): void
    {
        [$source, $product, $old] = $this->fixture();
        Schema::withoutForeignKeyConstraints(function () use ($old, $product): void {
            $old->delete();
            $product->delete();
        });

        $response = $this->send($source)->assertStatus(202)
            ->assertJsonPath('status', 'processed')->assertJsonPath('needs_review', true);
        $item = Order::findOrFail($response->json('order_id'))->items()->first();

        $this->assertNull($item->product_id);
        $this->assertNull($item->product_variant_id);
        $this->assertSame('Тапочки з еко-хутра', $item->product_title);
        $this->assertSame('36/37 - 24.5см', $item->size);
        $this->assertEquals(399, (float) $item->total);
    }

    public function test_intake_repairs_variant_mapped_to_wrong_parent_product(): void
    {
        [$source, $product, $variant, $map] = $this->fixture();
        $other = $product->replicate();
        $other->sku = 'OTHER';
        $other->save();
        $map->update(['product_id' => $other->id]);

        $response = $this->send($source)->assertStatus(202)->assertJsonPath('status', 'processed');
        $item = Order::findOrFail($response->json('order_id'))->items()->first();

        $this->assertSame($product->id, $item->product_id);
        $this->assertSame($variant->id, $item->product_variant_id);
        $this->assertSame($product->id, $map->fresh()->product_id);
    }

    private function fixture(): array
    {
        $source = OrderSource::create([
            'code' => 'test-site', 'name' => 'Тестовий сайт', 'type' => 'order', 'sort_order' => 0,
            'is_default' => false, 'is_integration' => true, 'mode' => 'push', 'adapter' => 'custom',
            'api_key' => 'test-api-key', 'is_enabled' => true,
        ]);
        $product = Product::create([
            'title' => 'Тапочки', 'sku' => '2019', 'currency' => 'UAH', 'cost_price' => 150,
            'sale_price' => 399, 'stock_qty' => 0, 'min_stock' => 0, 'is_active' => true,
        ]);
        $variant = $this->variant($product);
        $map = ExternalProduct::create([
            'source_id' => $source->id, 'external_id' => '4', 'external_size' => '36/37 - 24.5см',
            'product_id' => $product->id, 'product_variant_id' => $variant->id,
        ]);

        return [$source, $product, $variant, $map];
    }

    private function variant(Product $product): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $product->id, 'size' => '36/37р - 24-24,5см', 'sku' => '2019-36-37',
            'stock_qty' => 5, 'is_active' => true,
        ]);
    }

    private function send(OrderSource $source)
    {
        return $this->postJson('/api/v1/orders/intake', [
            'external_order_id' => 'WEB-STALE',
            'customer' => ['first_name' => 'Тест', 'phone' => '0997776655'],
            'items' => [['external_id' => '4', 'sku' => '2019-36-37', 'name' => 'Тапочки з еко-хутра', 'size' => '36/37 - 24.5см', 'qty' => 1, 'price' => 399]],
            'delivery' => ['type' => 'warehouse', 'city_name' => 'Львів'],
            'payment' => ['method' => 'cod', 'currency' => 'UAH'],
        ], ['X-Api-Key' => $source->api_key]);
    }
}
