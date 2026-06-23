<?php

namespace Tests\Feature\Integration;

use App\Models\Customer;
use App\Models\ExternalProduct;
use App\Models\Order;
use App\Models\OrderSource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Integration\ExternalOrderImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExternalOrderImportTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSource(array $attrs = []): OrderSource
    {
        return OrderSource::create(array_merge([
            'code' => 'site-' . uniqid(),
            'name' => 'Test Site',
            'type' => 'order',
            'sort_order' => 0,
            'is_default' => false,
            'is_integration' => true,
            'mode' => 'push',
            'adapter' => 'custom',
            'api_key' => 'dkey_' . uniqid(),
            'is_enabled' => true,
        ], $attrs));
    }

    protected function makeVariant(string $sku, string $size = '40'): ProductVariant
    {
        $product = Product::create([
            'title' => 'Тапочки',
            'sku' => 'P-' . $sku,
            'currency' => 'UAH',
            'cost_price' => 0,
            'sale_price' => 500,
            'stock_qty' => 0,
            'min_stock' => 0,
            'is_active' => true,
        ]);

        return ProductVariant::create([
            'product_id' => $product->id,
            'size' => $size,
            'sku' => $sku,
            'stock_qty' => 10,
            'is_active' => true,
        ]);
    }

    protected function canonical(array $overrides = []): array
    {
        return array_merge([
            'external_order_id' => 'T-1',
            'customer' => ['first_name' => 'Іван', 'phone' => '0991234567'],
            'items' => [],
            'delivery' => ['type' => 'warehouse', 'city_name' => 'Київ', 'warehouse_name' => 'Відділення №1'],
            'payment' => ['method' => 'cod', 'currency' => 'UAH'],
            'currency' => 'UAH',
        ], $overrides);
    }

    public function test_matches_item_by_variant_sku(): void
    {
        $source = $this->makeSource();
        $variant = $this->makeVariant('6027-36-37', '36/37');

        $order = app(ExternalOrderImporter::class)->import($source, $this->canonical([
            'items' => [[
                'external_id' => 'EX1', 'sku' => '6027-36-37', 'name' => 'Тапочки', 'size' => '36/37', 'qty' => 2, 'price' => 500,
            ]],
        ]));

        $item = $order->items()->first();
        $this->assertSame($variant->id, $item->product_variant_id);
        $this->assertSame($variant->product_id, $item->product_id);
        $this->assertFalse($order->fresh()->needs_review);
        $this->assertEquals(1000, (float) $item->total);
    }

    public function test_unmatched_item_flags_needs_review_but_keeps_order(): void
    {
        $source = $this->makeSource();

        $order = app(ExternalOrderImporter::class)->import($source, $this->canonical([
            'items' => [[
                'external_id' => 'EX9', 'sku' => 'UNKNOWN', 'name' => 'Невідоме', 'size' => '42', 'qty' => 1, 'price' => 300,
            ]],
        ]));

        $item = $order->items()->first();
        $this->assertNull($item->product_id);
        $this->assertNull($item->product_variant_id);
        $this->assertSame('Невідоме', $item->product_title); // snapshot збережено
        $this->assertTrue($order->fresh()->needs_review);
    }

    public function test_idempotent_by_external_order_id(): void
    {
        $source = $this->makeSource();
        $importer = app(ExternalOrderImporter::class);
        $canonical = $this->canonical(['items' => [['external_id' => 'E', 'sku' => 'X', 'name' => 'N', 'size' => '40', 'qty' => 1, 'price' => 100]]]);

        $first = $importer->import($source, $canonical);
        $second = $importer->import($source, $canonical);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Order::where('source_id', $source->id)->count());
    }

    public function test_customer_deduplicated_by_normalized_phone(): void
    {
        $source = $this->makeSource();
        $existing = Customer::create([
            'first_name' => 'Стара',
            'phone' => '+38 (099) 123-45-67',
            'phone_normalized' => '380991234567',
        ]);

        $order = app(ExternalOrderImporter::class)->import($source, $this->canonical([
            'external_order_id' => 'T-77',
            'customer' => ['first_name' => 'Іван', 'phone' => '0991234567'],
            'items' => [['external_id' => 'E', 'sku' => 'X', 'name' => 'N', 'size' => '40', 'qty' => 1, 'price' => 100]],
        ]));

        $this->assertSame($existing->id, $order->customer_id);
        $this->assertSame(1, Customer::count());
    }

    public function test_imports_nova_poshta_refs_into_delivery(): void
    {
        $source = $this->makeSource();

        $order = app(ExternalOrderImporter::class)->import($source, $this->canonical([
            'external_order_id' => 'T-REF',
            'items' => [['external_id' => 'E', 'sku' => 'X', 'name' => 'N', 'size' => '40', 'qty' => 1, 'price' => 100]],
            'delivery' => [
                'type' => 'warehouse',
                'city_name' => 'Рівне, Рівненська обл.',
                'city_ref' => 'city-ref-123',
                'warehouse_name' => 'Відділення №8',
                'warehouse_ref' => 'wh-ref-456',
            ],
        ]));

        // Реф-и збережені → ТТН створиться й доставку не треба перевибирати.
        $d = $order->delivery;
        $this->assertSame('city-ref-123', $d->city_ref);
        $this->assertSame('wh-ref-456', $d->warehouse_ref);
    }

    public function test_memory_mapping_takes_priority_over_sku(): void
    {
        $source = $this->makeSource();
        $this->makeVariant('SKU-A', '40');                  // по SKU вело б сюди
        $memoryVariant = $this->makeVariant('SKU-B', '41'); // пам'ять веде сюди

        ExternalProduct::create([
            'source_id' => $source->id,
            'external_id' => 'EXP',
            'external_size' => '40',
            'product_id' => $memoryVariant->product_id,
            'product_variant_id' => $memoryVariant->id,
        ]);

        $order = app(ExternalOrderImporter::class)->import($source, $this->canonical([
            'external_order_id' => 'T-MEM',
            'items' => [['external_id' => 'EXP', 'sku' => 'SKU-A', 'name' => 'N', 'size' => '40', 'qty' => 1, 'price' => 100]],
        ]));

        $item = $order->items()->first();
        $this->assertSame($memoryVariant->id, $item->product_variant_id); // перемагає пам'ять, не SKU-A
    }
}
