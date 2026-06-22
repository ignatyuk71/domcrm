<?php

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Запобіжники генерації ТТН Нової Пошти (OrderController::generateTTN).
 * Перевіряємо, що ТТН не створюється з неповними даними доставки.
 */
class OrderTtnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Жодного реального запиту до Нової Пошти не має вилетіти.
        Http::preventStrayRequests();
    }

    private function operator(): User
    {
        return User::factory()->create(['role' => User::ROLE_OPERATOR, 'is_active' => true]);
    }

    private function orderWithDelivery(array $delivery): Order
    {
        $order = Order::create([
            'order_number' => 'T-' . uniqid(),
            'status' => 'new',
            'payment_status' => 'unpaid',
            'currency' => 'UAH',
        ]);
        OrderDelivery::create(array_merge(['order_id' => $order->id], $delivery));

        return $order;
    }

    public function test_ttn_rejected_without_city(): void
    {
        $order = $this->orderWithDelivery([
            'delivery_type' => 'warehouse',
            'city_ref' => null,
            'warehouse_ref' => 'wh-1',
        ]);

        $this->actingAs($this->operator())
            ->postJson("/orders/{$order->id}/generate-ttn")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Не заповнені дані міста']);

        $this->assertNull($order->fresh()->delivery->ttn);
    }

    public function test_ttn_rejected_without_warehouse(): void
    {
        $order = $this->orderWithDelivery([
            'delivery_type' => 'warehouse',
            'city_ref' => 'city-1',
            'warehouse_ref' => null,
        ]);

        $this->actingAs($this->operator())
            ->postJson("/orders/{$order->id}/generate-ttn")
            ->assertStatus(422);

        $this->assertNull($order->fresh()->delivery->ttn);
    }
}
