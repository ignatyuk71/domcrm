<?php

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderPaymentStatusTest extends TestCase
{
    use RefreshDatabase;

    private function order(): Order
    {
        $order = Order::create([
            'order_number' => 'PAY-STATUS-1', 'status' => 'shipped',
            'payment_status' => 'unpaid', 'currency' => 'UAH',
            'comment_internal' => 'Залишити нотатку',
        ]);
        $order->items()->create(['product_title' => 'Товар', 'qty' => 1, 'price' => 585, 'total' => 585]);
        $order->payment()->create(['method' => 'card', 'currency' => 'UAH', 'prepay_amount' => 0]);

        return $order;
    }

    public function test_owner_and_operator_can_change_payment_status_without_changing_the_order(): void
    {
        $order = $this->order();
        foreach ([User::ROLE_OWNER, User::ROLE_OPERATOR] as $role) {
            $user = User::factory()->create(['role' => $role, 'is_active' => true]);
            foreach (['paid', 'prepayment', 'refund', 'unpaid'] as $status) {
                $this->actingAs($user)->patchJson('/orders/'.$order->id.'/payment-status', [
                    'payment_status' => $status,
                    'status' => 'delivered_paid', 'comment_internal' => 'Не застосовувати',
                    'payment' => ['method' => 'cod', 'paid_amount' => 999],
                ])->assertOk()->assertJsonPath('data.payment_status', $status)
                    ->assertJsonPath('data.payment.method', 'card')
                    ->assertJsonPath('data.payment.paid_amount', null);

                $this->assertDatabaseHas('orders', [
                    'id' => $order->id, 'payment_status' => $status,
                    'status' => 'shipped', 'comment_internal' => 'Залишити нотатку',
                ]);
            }
        }
        $this->assertDatabaseCount('fiscal_receipts', 0);
        $this->assertDatabaseCount('fiscal_queue', 0);
        $this->assertDatabaseCount('order_status_changes', 0);
    }

    public function test_payment_status_is_persisted_in_the_list_and_order_card(): void
    {
        $order = $this->order();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_OPERATOR, 'is_active' => true]));
        $this->patchJson('/orders/'.$order->id.'/payment-status', ['payment_status' => 'paid'])->assertOk();
        $this->getJson('/orders/'.$order->id)->assertOk()->assertJsonPath('data.payment_status', 'paid');
        $this->getJson('/orders/list?search=PAY-STATUS-1')->assertOk()->assertJsonPath('data.0.payment_status', 'paid');
    }

    public function test_manual_status_does_not_erase_wayforpay_payment_evidence(): void
    {
        $order = $this->order();
        $order->payment->update([
            'provider' => 'wayforpay', 'paid_amount' => 585,
            'transaction_id' => 'verified-payment', 'paid_at' => now()->startOfSecond(),
        ]);
        $payment = $order->payment->fresh()->getAttributes();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'is_active' => true]))
            ->patchJson('/orders/'.$order->id.'/payment-status', ['payment_status' => 'refund'])
            ->assertOk()->assertJsonPath('data.payment_status', 'refund')
            ->assertJsonPath('data.payment.transaction_id', 'verified-payment');

        $this->assertSame($payment, $order->payment->fresh()->getAttributes());
        $this->assertDatabaseCount('fiscal_receipts', 0);
    }

    public function test_invalid_or_missing_status_is_rejected_without_changing_payment(): void
    {
        $order = $this->order();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_OPERATOR, 'is_active' => true]));
        foreach ([[], ['payment_status' => null], ['payment_status' => 'unknown'], ['payment_status' => ['paid']]] as $payload) {
            $this->patchJson('/orders/'.$order->id.'/payment-status', $payload)
                ->assertUnprocessable()->assertJsonValidationErrors('payment_status');
            $this->assertSame('unpaid', $order->fresh()->payment_status);
        }
    }

    public function test_packer_and_guest_cannot_change_payment_status(): void
    {
        $order = $this->order();
        $this->patchJson('/orders/'.$order->id.'/payment-status', ['payment_status' => 'paid'])->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_PACKER, 'is_active' => true]))
            ->patchJson('/orders/'.$order->id.'/payment-status', ['payment_status' => 'paid'])->assertForbidden();
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }
}
