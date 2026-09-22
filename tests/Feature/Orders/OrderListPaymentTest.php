<?php

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderListPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_includes_online_payment_details_used_by_the_order_card(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_OPERATOR, 'is_active' => true]);
        $order = Order::create([
            'order_number' => '6527', 'external_id' => '4987',
            'status' => 'new', 'payment_status' => 'paid', 'currency' => 'UAH',
        ]);
        $order->payment()->create([
            'method' => 'card', 'provider' => 'wayforpay', 'currency' => 'UAH',
            'paid_amount' => 5, 'transaction_id' => 'test-payment-4987',
            'paid_at' => now()->startOfSecond(),
        ]);

        $listed = $this->actingAs($user)->getJson('/orders/list?search=6527')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.payment_status', 'paid')
            ->assertJsonPath('data.0.payment.method', 'card')
            ->assertJsonPath('data.0.payment.provider', 'wayforpay')
            ->assertJsonPath('data.0.payment.paid_amount', '5.00')
            ->assertJsonPath('data.0.payment.transaction_id', 'test-payment-4987')
            ->json('data.0.payment');
        $shown = $this->getJson('/orders/'.$order->id)->assertOk()->json('data.payment');

        // Список і окрема картка мають отримувати ті самі реквізити оплати.
        foreach (['method', 'provider', 'paid_amount', 'transaction_id', 'paid_at'] as $field) {
            $this->assertSame($shown[$field], $listed[$field], $field);
        }
    }
}
