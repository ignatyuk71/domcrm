<?php

namespace Tests\Feature\Fiscal;

use App\Jobs\FiscalizeOrderJob;
use App\Models\CheckboxSetting;
use App\Models\FiscalReceipt;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Status;
use App\Services\CheckboxService;
use App\Services\FiscalQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutomaticFiscalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-03 12:00:00');
        foreach (['new', 'packing', 'delivered', 'delivered_paid', 'cancelled', 'returned'] as $code) {
            Status::create(['type' => 'order', 'code' => $code, 'name' => $code]);
        }
        CheckboxSetting::create([
            'api_url' => 'https://api.checkbox.in.ua/api/v1',
            'license_key' => 'TEST', 'login' => 'test', 'password' => 'test',
            'enabled' => true, 'queue_enabled' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'queue_process_time' => '08:30',
            'last_opened_at' => now()->startOfDay()->addHours(8),
        ]);
        Http::preventStrayRequests();
        $this->fakeCheckbox();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeCheckbox(int $sellStatus = 200, ?array $existingReceipt = null): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($sellStatus, $existingReceipt) {
            if (str_ends_with($request->url(), '/cashier/signin')) {
                return Http::response(['access_token' => 'test-token']);
            }
            if (str_ends_with($request->url(), '/cashier/shift')) {
                return Http::response(['status' => 'OPENED']);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/receipts/')) {
                return Http::response($existingReceipt ?? ['message' => 'Не знайдено'], $existingReceipt ? 200 : 404);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/receipts/sell')) {
                return Http::response($sellStatus === 200
                    ? ['id' => $request['id'], 'status' => 'DONE', 'fiscal_code' => 'FC-TEST']
                    : ['message' => 'Тимчасова помилка Checkbox'], $sellStatus);
            }

            throw new \RuntimeException('Неочікуваний запит у тесті: '.$request->url());
        });
    }

    private function order(string $status = 'new', string $paymentStatus = 'paid', array $payment = []): Order
    {
        $order = Order::create([
            'order_number' => 'AUTO-'.Str::ulid(), 'status' => $status,
            'status_id' => Status::where('code', $status)->value('id'),
            'payment_status' => $paymentStatus, 'currency' => 'UAH',
        ]);
        $order->items()->create([
            'product_title' => 'Капці', 'sku' => 'AUTO-38', 'size' => '38',
            'price' => 500, 'qty' => 1, 'total' => 500,
        ]);
        OrderPayment::create(array_replace([
            'order_id' => $order->id, 'method' => 'card', 'provider' => 'wayforpay',
            'paid_amount' => 500, 'currency' => 'UAH', 'transaction_id' => 'txn-'.$order->id,
        ], $payment));

        return $order;
    }

    private function receipt(Order $order, int $amountCents, string $status = 'success', string $type = 'sell'): FiscalReceipt
    {
        return $order->fiscalReceipts()->create([
            'type' => $type, 'status' => $status, 'total_amount' => $amountCents,
            'uuid' => (string) Str::uuid(), 'payload_hash' => (string) Str::uuid(),
        ]);
    }

    private function tick(): void
    {
        $this->artisan('fiscal:delivered')->assertSuccessful();
        $this->artisan('fiscal:shift-manager')->assertSuccessful();
    }

    public function test_sweep_fiscalizes_confirmed_online_payment_without_completing_delivery(): void
    {
        $order = $this->order('packing');
        $this->tick();
        $this->tick();

        $this->assertDatabaseCount('fiscal_receipts', 1);
        $this->assertDatabaseCount('fiscal_queue', 1);
        $this->assertDatabaseHas('fiscal_receipts', ['order_id' => $order->id, 'status' => 'success', 'total_amount' => 50000]);
        $this->assertSame('packing', $order->fresh()->status);
        $this->assertSame($order->status_id, $order->fresh()->status_id);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertDatabaseCount('order_status_changes', 0);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/receipts/sell')));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/receipts/sell')
            && $r['goods'][0]['good']['name'] === 'Капці - 38'
            && $r['goods'][0]['good']['code'] === 'AUTO-38');
    }

    public function test_completed_paid_order_without_receipt_is_not_skipped(): void
    {
        $order = $this->order('delivered_paid', 'paid', ['method' => 'cod', 'provider' => null, 'paid_amount' => null]);
        $this->tick();
        $this->assertDatabaseHas('fiscal_receipts', ['order_id' => $order->id, 'status' => 'success', 'total_amount' => 50000]);
    }

    public function test_cash_on_delivery_still_waits_for_receipt_of_parcel(): void
    {
        $this->order('delivered', 'unpaid', ['method' => 'cod', 'provider' => null, 'paid_amount' => null]);
        $received = $this->order('delivered_paid', 'unpaid', ['method' => 'cod', 'provider' => null, 'paid_amount' => null]);
        $this->tick();
        $this->assertDatabaseCount('fiscal_receipts', 1);
        $this->assertDatabaseHas('fiscal_receipts', ['order_id' => $received->id, 'status' => 'success']);
    }

    public function test_unconfirmed_partial_and_bank_transfer_payments_do_not_trigger_early_receipts(): void
    {
        $this->order('new', 'unpaid', ['paid_amount' => null]);
        $this->order('new', 'prepayment', ['paid_amount' => 100]);
        $this->order('new', 'paid', ['paid_amount' => null]);
        $this->order('new', 'paid', ['paid_amount' => 499]);
        $this->order('new', 'paid', ['currency' => 'USD']);
        $this->order('new', 'paid', ['provider' => null]);
        $this->tick();
        $this->assertDatabaseCount('fiscal_queue', 0);
        $this->assertDatabaseCount('fiscal_receipts', 0);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/receipts/'));
    }

    public function test_only_unfiscalized_remainder_is_sent_after_manual_prepayment(): void
    {
        $order = $this->order();
        app(FiscalQueueService::class)->enqueueConfirmedOnlinePayment($order);
        $this->receipt($order, 10000);
        $this->tick();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/receipts/sell') && $r['payments'][0]['value'] === 40000);
        $this->assertSame(50000, (int) $order->fiscalReceipts()->where('status', 'success')->sum('total_amount'));
    }

    public function test_fully_fiscalized_order_never_creates_another_queue_item(): void
    {
        $order = $this->order();
        $this->receipt($order, 50000);
        app(FiscalQueueService::class)->enqueueConfirmedOnlinePayment($order);
        $this->tick();
        $this->assertDatabaseCount('fiscal_queue', 0);
        $this->assertDatabaseCount('fiscal_receipts', 1);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/receipts/'));
    }

    public function test_queue_respects_configured_time_window(): void
    {
        Carbon::setTestNow('2026-10-03 23:30:00');
        $order = $this->order();
        $item = app(FiscalQueueService::class)->enqueueConfirmedOnlinePayment($order);
        // Окремо перевіряємо чергу: менеджер зміни у цей час закривав би касу.
        $this->artisan('fiscal:delivered')->assertSuccessful();
        $this->assertSame('2026-10-04 08:30:00', $item->available_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('fiscal_receipts', 0);
        Http::assertNothingSent();

        Carbon::setTestNow('2026-10-04 08:20:00');
        $this->artisan('fiscal:delivered')->assertSuccessful();
        $this->assertDatabaseCount('fiscal_receipts', 0);
        $this->assertDatabaseCount('fiscal_queue', 1);
    }

    public function test_disabled_integration_creates_no_queue_or_receipt(): void
    {
        CheckboxSetting::query()->update(['enabled' => false]);
        $order = $this->order();
        $this->assertNull(app(FiscalQueueService::class)->enqueueConfirmedOnlinePayment($order));
        $this->tick();
        $this->assertDatabaseCount('fiscal_queue', 0);
        $this->assertDatabaseCount('fiscal_receipts', 0);
        Http::assertNothingSent();
    }

    public function test_disabled_queue_uses_scheduled_direct_fiscalization(): void
    {
        CheckboxSetting::query()->update(['queue_enabled' => false]);
        $order = $this->order();
        $this->assertNull(app(FiscalQueueService::class)->enqueueConfirmedOnlinePayment($order));
        $this->tick();
        $this->assertDatabaseCount('fiscal_queue', 0);
        $this->assertDatabaseHas('fiscal_receipts', ['order_id' => $order->id, 'status' => 'success']);
        $this->assertSame('new', $order->fresh()->status);
    }

    public function test_cancelled_returned_and_refunded_orders_are_excluded_even_after_enqueue(): void
    {
        $cancelled = $this->order();
        app(FiscalQueueService::class)->enqueueConfirmedOnlinePayment($cancelled);
        $cancelled->update(['status' => 'cancelled', 'status_id' => Status::where('code', 'cancelled')->value('id')]);
        $this->order('returned');
        $this->order('delivered_paid', 'refund');
        $returnedReceipt = $this->order();
        $this->receipt($returnedReceipt, 10000, 'success', 'return');
        $this->tick();
        $this->assertDatabaseCount('fiscal_receipts', 1);
        $this->assertDatabaseHas('fiscal_queue', ['order_id' => $cancelled->id, 'status' => 'skipped']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/receipts/'));
    }

    public function test_changed_order_amount_does_not_fiscalize_an_unconfirmed_increase(): void
    {
        $order = $this->order();
        app(FiscalQueueService::class)->enqueueConfirmedOnlinePayment($order);
        $order->items()->update(['total' => 600]);
        $this->tick();
        $this->assertDatabaseCount('fiscal_receipts', 0);
        $this->assertDatabaseHas('fiscal_queue', ['order_id' => $order->id, 'status' => 'error']);
        $this->assertSame('paid', $order->fresh()->payment_status);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/receipts/'));
    }

    public function test_checkbox_error_preserves_payment_and_retry_reuses_same_receipt_uuid(): void
    {
        $this->fakeCheckbox(400);
        $order = $this->order();
        $this->tick();
        $prior = $order->fiscalReceipts()->firstOrFail();
        $this->assertSame('error', $prior->status);
        $this->assertStringContainsString('Тимчасова помилка Checkbox', $prior->error_message);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('new', $order->fresh()->status);

        $this->fakeCheckbox();
        $this->tick();
        $this->assertDatabaseCount('fiscal_receipts', 1);
        $this->assertDatabaseCount('fiscal_queue', 1);
        $this->assertSame('success', $prior->fresh()->status);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/receipts/sell') && $r['id'] === $prior->uuid);
    }

    public function test_abandoned_processing_receipt_is_checked_in_checkbox_before_retry(): void
    {
        $order = $this->order();
        $prior = $this->receipt($order, 50000, 'processing');
        $this->fakeCheckbox(200, ['id' => $prior->uuid, 'status' => 'DONE', 'fiscal_code' => 'FC-OLD']);
        $this->tick();
        $this->assertSame('success', $prior->fresh()->status);
        $this->assertDatabaseCount('fiscal_receipts', 1);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/receipts/sell'));
    }

    public function test_exhausted_errors_are_not_reset_by_repeated_sweeps_or_confirmations(): void
    {
        $order = $this->order();
        $item = app(FiscalQueueService::class)->enqueueConfirmedOnlinePayment($order);
        $item->update(['status' => 'error', 'attempts' => FiscalQueueService::MAX_ATTEMPTS, 'last_error' => 'Збережена помилка']);
        app(FiscalQueueService::class)->enqueueConfirmedOnlinePayment($order);
        $this->tick();
        $this->tick();
        $this->assertDatabaseCount('fiscal_queue', 1);
        $this->assertSame('Збережена помилка', $item->fresh()->last_error);
        $this->assertSame('error', $item->fresh()->status);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/receipts/'));
    }

    public function test_stale_processing_queue_recovers_without_a_second_receipt(): void
    {
        $order = $this->order();
        $prior = $this->receipt($order, 50000, 'processing');
        $item = app(FiscalQueueService::class)->enqueueConfirmedOnlinePayment($order);
        $item->update(['status' => 'processing']);
        // updated_at моделі автоматичний, тому відтворюємо зупинку процесу прямим записом.
        \Illuminate\Support\Facades\DB::table('fiscal_queue')->where('id', $item->id)
            ->update(['updated_at' => now()->subMinutes(11)]);
        $this->fakeCheckbox(200, ['id' => $prior->uuid, 'status' => 'DONE', 'fiscal_code' => 'FC-RECOVERED']);

        $this->tick();
        $this->assertSame('success', $item->fresh()->status);
        $this->assertSame('success', $prior->fresh()->status);
        $this->assertDatabaseCount('fiscal_receipts', 1);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/receipts/sell'));
    }

    public function test_active_processing_queue_is_not_taken_by_another_worker(): void
    {
        $order = $this->order();
        $item = app(FiscalQueueService::class)->enqueueConfirmedOnlinePayment($order);
        $item->update(['status' => 'processing']);
        $this->tick();
        $this->assertSame('processing', $item->fresh()->status);
        $this->assertDatabaseCount('fiscal_receipts', 0);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/receipts/'));
    }

    public function test_automatic_job_checks_current_state_after_loading_stale_order(): void
    {
        $order = $this->order();
        $job = new FiscalizeOrderJob($order, amountCents: 50000, automatic: true);
        $order->fresh()->update(['payment_status' => 'refund']);
        $job->handle(app(CheckboxService::class));
        $this->assertDatabaseCount('fiscal_receipts', 0);
        Http::assertNothingSent();
    }
}
