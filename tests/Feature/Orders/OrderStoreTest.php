<?php

namespace Tests\Feature\Orders;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Захисний пояс для флоу створення замовлення (OrderController::store).
 */
class OrderStoreTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        return User::factory()->create(['role' => User::ROLE_OPERATOR, 'is_active' => true]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'customer' => ['first_name' => 'Іван', 'last_name' => 'Петренко', 'phone' => '+380991112233'],
            'order' => ['status' => 'new', 'payment_status' => 'unpaid', 'currency' => 'UAH'],
            'items' => [
                ['title' => 'Капці домашні', 'sku' => 'K1', 'qty' => 2, 'price' => 399],
                ['title' => 'Тапки вуличні', 'sku' => 'T1', 'qty' => 1, 'price' => 500],
            ],
            'payment' => ['method' => 'cod', 'currency' => 'UAH'],
            'delivery' => ['delivery_type' => 'warehouse'],
        ], $overrides);
    }

    public function test_creates_order_with_items_payment_and_delivery(): void
    {
        $user = $this->operator();

        $response = $this->actingAs($user)->postJson('/orders', $this->validPayload());

        $response->assertCreated();
        $order = Order::with(['items', 'payment', 'delivery', 'customer'])->latest('id')->first();

        $this->assertNotNull($order);
        $this->assertSame((string) $order->id, $order->order_number); // номер = id
        $this->assertSame($user->id, $order->manager_id);

        // Товари + порахований total (price * qty)
        $this->assertCount(2, $order->items);
        $this->assertEqualsCanonicalizing(
            [798.0, 500.0],
            $order->items->pluck('total')->map(fn ($t) => (float) $t)->all()
        );

        // Оплата і доставка
        $this->assertSame('cod', $order->payment->method);
        $this->assertSame('warehouse', $order->delivery->delivery_type);

        // Клієнт прив'язаний за телефоном
        $this->assertNotNull($order->customer);
        $this->assertSame('+380991112233', $order->customer->phone);
    }

    public function test_creates_postomat_delivery(): void
    {
        $payload = $this->validPayload(['delivery' => ['delivery_type' => 'postomat']]);

        $this->actingAs($this->operator())->postJson('/orders', $payload)->assertCreated();

        $delivery = Order::latest('id')->first()->delivery;
        $this->assertSame('postomat', $delivery->delivery_type);
        $this->assertSame(\App\Models\OrderDelivery::SERVICE_POSTOMAT, $delivery->service_type);
    }

    public function test_snapshots_product_cost_and_sale_type_for_analytics(): void
    {
        $product = Product::create([
            'title' => 'Аналітичний товар',
            'sku' => 'AN-1',
            'currency' => 'UAH',
            'cost_price' => 125.50,
            'sale_price' => 300,
        ]);

        $payload = $this->validPayload([
            'order' => ['sale_type' => 'wholesale'],
            'items' => [[
                'product_id' => $product->id,
                'title' => $product->title,
                'sku' => $product->sku,
                'qty' => 2,
                'price' => 250,
            ]],
        ]);

        $this->actingAs($this->operator())->postJson('/orders', $payload)->assertCreated();

        $order = Order::with('items')->latest('id')->firstOrFail();
        $this->assertSame('wholesale', $order->sale_type);
        $this->assertSame(125.50, (float) $order->items->first()->cost_price);
    }

    public function test_reuses_existing_customer_by_phone(): void
    {
        $existing = Customer::create([
            'first_name' => 'Старий',
            'last_name' => 'Клієнт',
            'phone' => '+380991112233',
        ]);

        $this->actingAs($this->operator())
            ->postJson('/orders', $this->validPayload())
            ->assertCreated();

        // Дублікат клієнта не створюється
        $this->assertSame(1, Customer::where('phone', '+380991112233')->count());
        $this->assertSame($existing->id, Order::latest('id')->first()->customer_id);
    }

    public function test_writes_phone_normalized_on_create(): void
    {
        $this->actingAs($this->operator())
            ->postJson('/orders', $this->validPayload())
            ->assertCreated();

        $customer = Order::latest('id')->first()->customer;
        $this->assertSame('380991112233', $customer->phone_normalized);
    }

    public function test_dedups_customer_across_phone_formats(): void
    {
        $existing = Customer::create([
            'first_name' => 'Існує', 'phone' => '+380991112233', 'phone_normalized' => '380991112233',
        ]);

        $this->actingAs($this->operator())
            ->postJson('/orders', $this->validPayload(['customer' => [
                'first_name' => 'Новий', 'phone' => '0991112233', // інший формат — той самий номер
            ]]))
            ->assertCreated();

        // Дубль не створено, замовлення прив'язане до наявного клієнта.
        $this->assertSame(1, Customer::where('phone_normalized', '380991112233')->count());
        $this->assertSame($existing->id, Order::latest('id')->first()->customer_id);
    }

    public function test_rejects_warehouse_typed_without_selection(): void
    {
        $payload = $this->validPayload(['delivery' => [
            'delivery_type' => 'warehouse',
            'warehouse_name' => 'Відділення №5',
            // warehouse_ref відсутній — не обрано зі списку
        ]]);

        $this->actingAs($this->operator())
            ->postJson('/orders', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['delivery.warehouse_name']);

        $this->assertSame(0, Order::count());
    }

    public function test_rejects_city_typed_without_selection(): void
    {
        $payload = $this->validPayload(['delivery' => [
            'delivery_type' => 'warehouse',
            'city_name' => 'Київ', // без city_ref
        ]]);

        $this->actingAs($this->operator())
            ->postJson('/orders', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['delivery.city_name']);
    }

    public function test_allows_delivery_selected_from_list(): void
    {
        $payload = $this->validPayload(['delivery' => [
            'delivery_type' => 'warehouse',
            'city_name' => 'Київ', 'city_ref' => 'city-ref-1',
            'warehouse_name' => 'Відділення №5', 'warehouse_ref' => 'wh-ref-1',
        ]]);

        $this->actingAs($this->operator())
            ->postJson('/orders', $payload)
            ->assertCreated();
    }

    /** Курʼєр: вулиці може не бути в базі НП — street без ref має проходити. */
    public function test_allows_courier_street_without_ref(): void
    {
        $payload = $this->validPayload(['delivery' => [
            'delivery_type' => 'courier',
            'city_name' => 'Село', 'city_ref' => 'city-ref-1',
            'street_name' => 'вул. Богдана Хмельницького', // без street_ref
            'building' => '10',
        ]]);

        $this->actingAs($this->operator())
            ->postJson('/orders', $payload)
            ->assertCreated();
    }

    public function test_requires_at_least_one_item(): void
    {
        $payload = $this->validPayload();
        $payload['items'] = [];

        $this->actingAs($this->operator())
            ->postJson('/orders', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    public function test_update_clears_review_flag_when_all_items_are_mapped(): void
    {
        $product = Product::create([
            'title' => 'Капці домашні', 'sku' => 'K1', 'currency' => 'UAH',
            'cost_price' => 0, 'sale_price' => 399, 'stock_qty' => 1, 'min_stock' => 0, 'is_active' => true,
        ]);
        $order = Order::create([
            'order_number' => 'EXT-1', 'status' => 'new', 'payment_status' => 'unpaid',
            'currency' => 'UAH', 'needs_review' => true,
        ]);
        $order->items()->create([
            'product_title' => 'Нерозпізнані капці', 'sku' => 'EXT-K1', 'price' => 399, 'qty' => 1, 'total' => 399,
        ]);

        $payload = $this->validPayload();
        $payload['items'] = [[
            'product_id' => $product->id, 'title' => $product->title, 'sku' => $product->sku, 'qty' => 1, 'price' => 399,
        ]];

        $this->actingAs($this->operator())
            ->putJson("/orders/{$order->id}", $payload)
            ->assertOk();

        $this->assertFalse($order->fresh()->needs_review);
    }

    public function test_packer_role_is_forbidden(): void
    {
        $packer = User::factory()->create(['role' => User::ROLE_PACKER, 'is_active' => true]);

        $this->actingAs($packer)
            ->postJson('/orders', $this->validPayload())
            ->assertForbidden();

        $this->assertSame(0, Order::count());
    }

    public function test_guest_cannot_create_order(): void
    {
        $this->postJson('/orders', $this->validPayload())->assertUnauthorized();
        $this->assertSame(0, Order::count());
    }
}
