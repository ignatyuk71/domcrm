<?php

namespace Tests\Feature\Integration;

use App\Models\ExternalOrderRaw;
use App\Models\Order;
use App\Models\OrderSource;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderIntakeEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected string $url = '/api/v1/orders/intake';

    protected function source(array $attrs = []): OrderSource
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

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'external_order_id' => 'WEB-1',
            'customer' => ['first_name' => 'Веб', 'phone' => '0997776655'],
            'items' => [['external_id' => 'W1', 'sku' => 'NOPE', 'name' => 'Веб товар', 'size' => '40', 'qty' => 1, 'price' => 250]],
            'delivery' => ['type' => 'warehouse', 'city_name' => 'Львів', 'warehouse_name' => 'Відділення №5'],
            'payment' => ['method' => 'cod', 'currency' => 'UAH'],
        ], $overrides);
    }

    public function test_rejects_request_without_api_key(): void
    {
        $this->postJson($this->url, $this->payload())->assertStatus(401);
    }

    public function test_rejects_invalid_api_key(): void
    {
        $this->source();
        $this->postJson($this->url, $this->payload(), ['X-Api-Key' => 'wrong'])->assertStatus(401);
    }

    public function test_rejects_disabled_source(): void
    {
        $source = $this->source(['is_enabled' => false]);
        $this->postJson($this->url, $this->payload(), ['X-Api-Key' => $source->api_key])->assertStatus(403);
    }

    public function test_accepts_valid_order_and_creates_it(): void
    {
        $source = $this->source();

        $this->postJson($this->url, $this->payload(), ['X-Api-Key' => $source->api_key])
            ->assertStatus(202)
            ->assertJson(['accepted' => true, 'status' => 'processed']);

        $this->assertSame(1, Order::where('source_id', $source->id)->count());
        $this->assertSame(1, ExternalOrderRaw::where('source_id', $source->id)->count());

        $order = Order::where('source_id', $source->id)->first();
        $this->assertSame('WEB-1', $order->external_id);
        $this->assertTrue($order->needs_review); // sku NOPE не знайдено
    }

    public function test_duplicate_is_idempotent(): void
    {
        $source = $this->source();
        $headers = ['X-Api-Key' => $source->api_key];

        $this->postJson($this->url, $this->payload(), $headers)->assertStatus(202);
        $this->postJson($this->url, $this->payload(), $headers)->assertStatus(200)->assertJson(['duplicate' => true]);

        $this->assertSame(1, Order::where('source_id', $source->id)->count());
        $this->assertSame(1, ExternalOrderRaw::where('source_id', $source->id)->count());
    }

    public function test_links_item_by_sku_via_endpoint(): void
    {
        $source = $this->source();
        $product = Product::create([
            'title' => 'Тапочки', 'sku' => 'P1', 'currency' => 'UAH',
            'cost_price' => 0, 'sale_price' => 500, 'stock_qty' => 0, 'min_stock' => 0, 'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'size' => '40', 'sku' => 'VAR-40', 'stock_qty' => 5, 'is_active' => true,
        ]);

        $this->postJson($this->url, $this->payload([
            'external_order_id' => 'WEB-SKU',
            'items' => [['external_id' => 'WX', 'sku' => 'VAR-40', 'name' => 'Тапочки', 'size' => '40', 'qty' => 1, 'price' => 500]],
        ]), ['X-Api-Key' => $source->api_key])->assertStatus(202);

        $order = Order::where('source_id', $source->id)->first();
        $item = $order->items()->first();
        $this->assertSame($variant->id, $item->product_variant_id);
        $this->assertFalse($order->needs_review);
    }

    public function test_hmac_signature_enforced_when_secret_set(): void
    {
        $source = $this->source(['api_secret' => 'topsecret']);
        $json = json_encode($this->payload(['external_order_id' => 'WEB-HMAC']));

        // Без підпису → 401
        $this->call('POST', $this->url, [], [], [], [
            'HTTP_X_API_KEY' => $source->api_key,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], $json)->assertStatus(401);

        // З правильним підписом → 202
        $sig = hash_hmac('sha256', $json, 'topsecret');
        $this->call('POST', $this->url, [], [], [], [
            'HTTP_X_API_KEY' => $source->api_key,
            'HTTP_X_SIGNATURE' => $sig,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], $json)->assertStatus(202);
    }
}
