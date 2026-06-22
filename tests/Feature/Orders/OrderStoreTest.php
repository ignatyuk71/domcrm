<?php

namespace Tests\Feature\Orders;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
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
