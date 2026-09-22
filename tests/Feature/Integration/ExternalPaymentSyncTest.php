<?php

namespace Tests\Feature\Integration;

use App\Models\ExternalOrderRaw;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\OrderSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExternalPaymentSyncTest extends TestCase
{
    use RefreshDatabase;

    private function source(): OrderSource
    {
        return OrderSource::create([
            'code' => 'payment-site', 'name' => 'Сайт', 'type' => 'order',
            'is_integration' => true, 'mode' => 'push', 'adapter' => 'custom',
            'api_key' => 'payment-test-key', 'is_enabled' => true,
        ]);
    }

    private function send(OrderSource $source, array $payment, bool $full = true)
    {
        $payload = ['external_order_id' => '4987', 'payment' => $payment];
        if ($full) {
            $payload['items'] = [['sku' => 'TEST-1', 'name' => 'Товар', 'price' => 5, 'qty' => 1]];
        }

        return $this->postJson('/api/v1/orders/intake', $payload, ['X-Api-Key' => $source->api_key]);
    }

    public function test_imports_confirmed_payment_without_touching_fiscalization(): void
    {
        $source = $this->source();
        $this->send($source, [
            'method' => 'card', 'provider' => 'WayForPay', 'status' => 'paid',
            'paid_amount' => 5, 'currency' => 'UAH', 'transaction_id' => 'txn-1',
            'paid_at' => '2026-09-22T08:00:00Z',
        ])->assertStatus(202)->assertJsonPath('status', 'processed');

        $order = Order::firstOrFail();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('new', $order->status);
        $this->assertSame('wayforpay', $order->payment->provider);
        $this->assertSame('5.00', $order->payment->paid_amount);
        $this->assertSame('txn-1', $order->payment->transaction_id);
        $this->assertNull($order->payment->prepay_amount);
        $this->assertDatabaseCount('fiscal_receipts', 0);
        $this->assertDatabaseCount('fiscal_queue', 0);
    }

    public function test_later_confirmation_updates_only_payment_and_is_idempotent(): void
    {
        $source = $this->source();
        $this->send($source, ['method' => 'card', 'provider' => 'wayforpay', 'status' => 'unpaid'])
            ->assertJsonPath('status', 'processed');
        $order = Order::firstOrFail();
        $order->update(['status' => 'packing', 'comment_internal' => 'Чернетка менеджера']);
        $order->payment->update(['prepay_amount' => 1]);
        $delivery = $order->delivery->getAttributes();
        $payment = ['status' => 'paid', 'paid_amount' => 5, 'transaction_id' => 'txn-2'];
        $this->send($source, $payment, false)->assertStatus(202)->assertJsonPath('status', 'processed');
        $this->send($source, $payment, false)->assertStatus(200)->assertJsonPath('duplicate', true);
        $this->send($source, ['status' => 'unpaid', 'paid_amount' => 0], false)
            ->assertJsonPath('status', 'processed');
        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('packing', $order->status);
        $this->assertSame('Чернетка менеджера', $order->comment_internal);
        $this->assertSame($delivery, $order->delivery->getAttributes());
        $this->assertSame('5.00', $order->payment->paid_amount);
        $this->assertEquals(1, $order->payment->prepay_amount);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, OrderPayment::count());
        $this->assertSame(1, $order->items()->count());
        $this->assertSame(1, ExternalOrderRaw::count());
    }

    public function test_payment_method_alone_never_confirms_payment(): void
    {
        $this->send($this->source(), ['method' => 'wayforpay'])->assertJsonPath('status', 'processed');
        $order = Order::firstOrFail();
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertNull($order->payment->paid_amount);
        $this->assertSame('card', $order->payment->method);
        $this->assertSame('wayforpay', $order->payment->provider);
    }

    public function test_invalid_amount_or_currency_does_not_mark_order_paid_and_can_be_retried(): void
    {
        $source = $this->source();
        $this->send($source, ['method' => 'card'])->assertJsonPath('status', 'processed');
        $this->send($source, ['status' => 'paid', 'paid_amount' => 4], false)->assertJsonPath('status', 'failed');
        $this->assertSame('unpaid', Order::firstOrFail()->payment_status);
        $this->send($source, ['status' => 'paid', 'paid_amount' => 5, 'currency' => 'USD'], false)->assertJsonPath('status', 'failed');
        $this->assertSame('unpaid', Order::firstOrFail()->payment_status);
        $this->send($source, ['status' => 'paid', 'paid_amount' => 5, 'currency' => 'UAH'], false)->assertJsonPath('status', 'processed');
        $this->assertSame('paid', Order::firstOrFail()->payment_status);
    }

    public function test_partial_payment_cannot_be_reduced_by_delayed_notification(): void
    {
        $source = $this->source();
        $this->send($source, ['method' => 'card', 'status' => 'prepayment', 'paid_amount' => 3])->assertJsonPath('status', 'processed');
        $this->send($source, ['status' => 'prepayment', 'paid_amount' => 1], false)->assertJsonPath('status', 'processed');
        $this->assertSame('3.00', Order::firstOrFail()->payment->paid_amount);
    }

    public function test_rejects_invalid_payment_fields_before_saving_payload(): void
    {
        $source = $this->source();
        $this->send($source, ['status' => 'paid', 'paid_amount' => -1])->assertStatus(422);
        $this->assertSame(0, ExternalOrderRaw::count());
    }
    public function test_pending_queue_keeps_confirmation_when_an_older_unpaid_event_arrives(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $source = $this->source();
        $this->send($source, ['method' => 'card', 'provider' => 'wayforpay', 'status' => 'unpaid']);
        $this->send($source, ['status' => 'paid', 'paid_amount' => 5], false);
        $this->send($source, ['status' => 'unpaid', 'paid_amount' => 0], false);
        $raw = ExternalOrderRaw::firstOrFail();
        $this->assertSame('paid', $raw->payload['payment']['status']);
        $job = new \App\Jobs\ProcessExternalOrder($raw->id);
        $job->handle(app(\App\Services\Integration\ExternalOrderImporter::class));
        $job->handle(app(\App\Services\Integration\ExternalOrderImporter::class));
        $this->assertSame('paid', Order::firstOrFail()->payment_status);
        $this->assertSame(1, Order::count());
    }

    public function test_payment_update_cannot_create_an_empty_order(): void
    {
        $this->send($this->source(), ['status' => 'paid', 'paid_amount' => 5], false)->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_recovery_command_previews_then_applies_only_saved_confirmation(): void
    {
        $source = $this->source();
        $this->send($source, ['method' => 'card']);
        $order = Order::firstOrFail();
        $this->artisan('integrations:sync-payment', ['order' => $order->id, '--apply' => true])->assertFailed();
        $raw = ExternalOrderRaw::firstOrFail();
        $payload = $raw->payload;
        $payload['payment'] = ['method' => 'card', 'provider' => 'wayforpay', 'status' => 'paid', 'paid_amount' => 5];
        $raw->update(['payload' => $payload]);
        $this->artisan('integrations:sync-payment', ['order' => $order->id])->assertSuccessful();
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertNull($order->payment()->first()->paid_amount);
        $this->artisan('integrations:sync-payment', ['order' => $order->id, '--apply' => true])->assertSuccessful();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('5.00', $order->payment()->first()->paid_amount);
        $this->assertDatabaseCount('fiscal_receipts', 0);
        $this->assertDatabaseCount('fiscal_queue', 0);
    }

    public function test_refunded_order_cannot_be_marked_paid_again_by_an_old_confirmation(): void
    {
        $source = $this->source();
        $this->send($source, ['method' => 'card']);
        $order = Order::firstOrFail();
        $order->update(['payment_status' => 'refund']);
        $this->send($source, ['status' => 'paid', 'paid_amount' => 5], false)->assertJsonPath('status', 'processed');
        $this->assertSame('refund', $order->fresh()->payment_status);
    }

}
