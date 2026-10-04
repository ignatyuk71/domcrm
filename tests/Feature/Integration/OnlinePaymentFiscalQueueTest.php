<?php

namespace Tests\Feature\Integration;

use App\Models\CheckboxSetting;
use App\Models\ExternalOrderRaw;
use App\Models\Order;
use App\Models\OrderSource;
use App\Models\Status;
use App\Services\Integration\ExternalPaymentSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OnlinePaymentFiscalQueueTest extends TestCase
{
    use RefreshDatabase;

    private OrderSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-03 12:00:00');
        Http::preventStrayRequests();
        Http::fake();
        Status::create(['type' => 'order', 'code' => 'delivered_paid', 'name' => 'Завершено']);
        CheckboxSetting::create([
            'enabled' => true, 'queue_enabled' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'queue_process_time' => '08:30',
        ]);
        $this->source = OrderSource::create([
            'code' => 'online-site', 'name' => 'Сайт', 'type' => 'order',
            'is_integration' => true, 'mode' => 'push', 'adapter' => 'custom',
            'api_key' => 'test-key', 'api_secret' => 'test-secret', 'is_enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function send(array $payment, bool $full = true, bool $validSignature = true)
    {
        $payload = ['external_order_id' => 'ONLINE-1', 'payment' => $payment];
        if ($full) {
            $payload['items'] = [['sku' => 'TEST', 'name' => 'Капці', 'price' => 500, 'qty' => 1]];
        }
        $body = json_encode($payload);

        return $this->call('POST', '/api/v1/orders/intake', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_API_KEY' => $this->source->api_key,
            'HTTP_X_SIGNATURE' => $validSignature ? hash_hmac('sha256', $body, 'test-secret') : 'invalid',
        ], $body);
    }

    public function test_paid_import_persists_payment_without_fiscal_queue_or_checkbox_request(): void
    {
        $this->send([
            'method' => 'card', 'provider' => 'WayForPay', 'status' => 'paid',
            'paid_amount' => 500, 'currency' => 'UAH', 'transaction_id' => 'txn-1',
        ])->assertStatus(202)->assertJsonPath('status', 'processed');

        $order = Order::firstOrFail();
        $this->assertSame('new', $order->status);
        $this->assertSame('paid', $order->payment_status);
        $this->assertDatabaseCount('fiscal_queue', 0);
        $this->assertDatabaseCount('fiscal_receipts', 0);
        Http::assertNothingSent();
    }

    public function test_later_payment_confirmation_waits_for_final_status_and_keeps_manager_work(): void
    {
        $this->send(['method' => 'card', 'provider' => 'wayforpay', 'status' => 'unpaid'])
            ->assertJsonPath('status', 'processed');
        $this->assertDatabaseCount('fiscal_queue', 0);
        $order = Order::firstOrFail();
        $order->update(['status' => 'packing', 'comment_internal' => 'Чернетка']);
        $payment = ['status' => 'paid', 'paid_amount' => 500, 'currency' => 'UAH', 'transaction_id' => 'txn-2'];
        $this->send($payment, false)->assertJsonPath('status', 'processed');
        $this->send($payment, false)->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('fiscal_queue', 0);
        $this->assertSame('packing', $order->fresh()->status);
        $this->assertSame('Чернетка', $order->fresh()->comment_internal);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $order->update(['status' => 'delivered_paid', 'status_id' => Status::where('code', 'delivered_paid')->value('id')]);
        $this->artisan('fiscal:delivered')->assertSuccessful();
        $this->artisan('fiscal:delivered')->assertSuccessful();
        $this->assertDatabaseCount('fiscal_queue', 1);
        $this->assertDatabaseCount('fiscal_receipts', 0);
        Http::assertNothingSent();
    }

    public function test_invalid_signature_amount_and_currency_create_no_fiscal_queue(): void
    {
        $payment = ['method' => 'card', 'provider' => 'wayforpay', 'status' => 'paid', 'paid_amount' => 500];
        $this->send($payment, true, false)->assertUnauthorized();
        $this->assertDatabaseCount('orders', 0);
        $this->send(array_replace($payment, ['paid_amount' => 499]))->assertJsonPath('status', 'failed');
        $this->assertDatabaseCount('orders', 0);
        $this->send(['method' => 'card', 'provider' => 'wayforpay', 'status' => 'unpaid', 'paid_amount' => 0])->assertJsonPath('status', 'processed');
        $this->send(array_replace($payment, ['currency' => 'USD']), false)->assertJsonPath('status', 'failed');
        $this->assertDatabaseCount('fiscal_queue', 0);
        $this->assertDatabaseCount('fiscal_receipts', 0);
        Http::assertNothingSent();
    }

    public function test_queue_rolls_back_together_with_payment(): void
    {
        $this->send(['method' => 'card', 'provider' => 'wayforpay', 'status' => 'unpaid']);
        $order = Order::firstOrFail();
        $order->update(['status' => 'delivered_paid', 'status_id' => Status::where('code', 'delivered_paid')->value('id')]);
        try {
            DB::transaction(function () use ($order) {
                app(ExternalPaymentSynchronizer::class)->sync($order, ['status' => 'paid', 'paid_amount' => 500]);
                $this->assertDatabaseCount('fiscal_queue', 1);
                throw new \RuntimeException('Тест відкату');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('Тест відкату', $e->getMessage());
        }

        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertDatabaseCount('fiscal_queue', 0);
        Http::assertNothingSent();
    }

    public function test_payment_recovery_saves_payment_without_fiscalizing_new_order(): void
    {
        $this->send(['method' => 'card', 'provider' => 'wayforpay', 'status' => 'unpaid']);
        $raw = ExternalOrderRaw::firstOrFail();
        $payload = $raw->payload;
        $payload['payment'] = ['method' => 'card', 'provider' => 'wayforpay', 'status' => 'paid', 'paid_amount' => 500];
        $raw->update(['payload' => $payload]);
        $order = Order::firstOrFail();

        $this->artisan('integrations:sync-payment', ['order' => $order->id])->assertSuccessful();
        $this->assertDatabaseCount('fiscal_queue', 0);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->artisan('integrations:sync-payment', ['order' => $order->id, '--apply' => true])->assertSuccessful();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertDatabaseCount('fiscal_queue', 0);
        $this->assertDatabaseCount('fiscal_receipts', 0);
        Http::assertNothingSent();
    }
}
