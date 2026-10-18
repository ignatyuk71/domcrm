<?php

namespace Tests\Feature\Fiscal;

use App\Jobs\FiscalizeOrderJob;
use App\Models\CheckboxSetting;
use App\Models\FiscalReceipt;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Status;
use App\Services\CheckboxService;
use App\Services\FiscalizationEligibility;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShippedPaidFiscalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Europe/Kyiv']);
        Carbon::setTestNow(Carbon::parse('2026-10-17 15:00:00', 'Europe/Kyiv'));
        foreach (['new', 'confirmed', 'packing', 'packed', 'shipped', 'delivered', 'delivered_paid', 'cancelled', 'returned'] as $code) {
            Status::create(['type' => 'order', 'code' => $code, 'name' => $code]);
        }
        CheckboxSetting::create([
            'api_url' => 'https://api.checkbox.in.ua/api/v1',
            'license_key' => 'TEST', 'login' => 'test', 'password' => 'test',
            'enabled' => true, 'queue_enabled' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'queue_process_time' => '08:30',
            'last_opened_at' => now()->startOfDay()->addHours(8),
        ]);

        // Жоден тест не звертається до реальної каси або платіжного сервісу.
        Http::preventStrayRequests();
        $this->fakeCheckbox();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeCheckbox(int $sellStatus = 200): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($sellStatus) {
            if (str_ends_with($request->url(), '/cashier/signin')) {
                return Http::response(['access_token' => 'test-token']);
            }
            if (str_ends_with($request->url(), '/cashier/shift')) {
                return Http::response(['status' => 'OPENED']);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/receipts/')) {
                return Http::response(['message' => 'Не знайдено'], 404);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/receipts/sell')) {
                return Http::response($sellStatus === 200
                    ? ['id' => $request['id'], 'status' => 'DONE', 'fiscal_code' => 'FC-SHIPPED']
                    : ['message' => 'Тимчасова помилка Checkbox'], $sellStatus);
            }

            throw new \RuntimeException('Неочікуваний запит у тесті: '.$request->url());
        });
    }

    private function order(string $status = 'shipped', string $paymentStatus = 'paid', array $payment = []): Order
    {
        $order = Order::create([
            'order_number' => 'SHIP-'.Str::ulid(), 'status' => $status,
            'status_id' => Status::where('code', $status)->value('id'),
            'payment_status' => $paymentStatus, 'currency' => 'UAH',
        ]);
        $order->items()->create([
            'product_title' => 'Капці', 'sku' => 'SHIPPED-38', 'size' => '38',
            'price' => 500, 'qty' => 1, 'total' => 500,
        ]);
        OrderPayment::create(array_replace([
            'order_id' => $order->id, 'method' => 'card', 'provider' => null,
            'paid_amount' => null, 'currency' => 'UAH',
        ], $payment));

        return $order;
    }

    private function receipt(Order $order, int $amountCents, string $type = FiscalReceipt::TYPE_SELL): FiscalReceipt
    {
        return $order->fiscalReceipts()->create([
            'type' => $type, 'status' => FiscalReceipt::STATUS_SUCCESS, 'total_amount' => $amountCents,
            'uuid' => (string) Str::uuid(), 'payload_hash' => (string) Str::uuid(),
        ]);
    }

    public function test_shipped_paid_bank_and_wayforpay_orders_are_fiscalized_once_without_finishing_delivery(): void
    {
        $bank = $this->order();
        $online = $this->order(payment: ['provider' => 'wayforpay', 'paid_amount' => 500]);

        $this->artisan('fiscal:shipped-paid')->assertSuccessful();
        $this->artisan('fiscal:shipped-paid')->assertSuccessful();

        $this->assertDatabaseCount('fiscal_receipts', 2);
        $this->assertDatabaseCount('fiscal_queue', 0);
        $this->assertDatabaseCount('order_status_changes', 0);
        foreach ([$bank, $online] as $order) {
            $this->assertDatabaseHas('fiscal_receipts', [
                'order_id' => $order->id, 'status' => 'success', 'total_amount' => 50000,
            ]);
            $this->assertSame('shipped', $order->fresh()->status);
            $this->assertSame($order->status_id, $order->fresh()->status_id);
            $this->assertSame('paid', $order->fresh()->payment_status);
        }
        $this->assertCount(2, Http::recorded(fn ($r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/receipts/sell')));
    }

    public function test_legacy_bank_method_aliases_and_wayforpay_method_are_selected(): void
    {
        $eligible = [];
        foreach (['card', 'invoice', 'transfer', 'bank_transfer', 'bank', 'cashless'] as $method) {
            $order = $this->order(payment: ['provider' => '']);
            // Відтворюємо старе значення в БД без нормалізації через setter моделі.
            DB::table('order_payments')->where('order_id', $order->id)->update(['method' => $method]);
            $eligible[] = $order->id;
        }
        $eligible[] = $this->order(payment: ['method' => 'wayforpay', 'paid_amount' => 500])->id;

        $eligibility = app(FiscalizationEligibility::class);
        $selected = $eligibility->shippedPaidCandidates(Order::query())->get();
        $this->assertEqualsCanonicalizing($eligible, $selected->modelKeys());
        foreach ($selected as $order) {
            $this->assertTrue($eligibility->hasConfirmedShippedPayment($order, 50000));
        }
        Http::assertNothingSent();
    }

    public function test_other_statuses_unpaid_payments_and_unsupported_methods_are_not_fiscalized(): void
    {
        foreach (['new', 'confirmed', 'packing', 'packed', 'delivered_paid', 'cancelled', 'returned'] as $status) {
            $this->order($status);
        }
        foreach (['unpaid', 'prepayment', 'refund'] as $paymentStatus) {
            $this->order(paymentStatus: $paymentStatus);
        }
        foreach (['cash', 'cod', 'novapay_cod', 'prepay'] as $method) {
            $this->order(payment: ['method' => $method]);
        }
        $this->order(payment: ['provider' => 'liqpay', 'paid_amount' => 500]);
        $withoutPayment = $this->order();
        $withoutPayment->payment()->delete();
        $zeroTotal = $this->order();
        $zeroTotal->items()->update(['total' => 0]);

        $this->artisan('fiscal:shipped-paid')->assertSuccessful();

        $this->assertDatabaseCount('fiscal_receipts', 0);
        $this->assertDatabaseCount('fiscal_queue', 0);
        Http::assertNothingSent();
    }

    public function test_wayforpay_still_requires_confirmed_full_amount_and_uah(): void
    {
        foreach ([null, 0, 100, 499] as $paidAmount) {
            $this->order(payment: ['provider' => 'wayforpay', 'paid_amount' => $paidAmount]);
        }
        $this->order(payment: ['provider' => 'wayforpay', 'paid_amount' => 500, 'currency' => 'USD']);
        $otherCurrency = $this->order(payment: ['provider' => 'wayforpay', 'paid_amount' => 500]);
        $otherCurrency->update(['currency' => 'USD']);
        $this->order(payment: ['method' => 'cod', 'provider' => 'wayforpay', 'paid_amount' => 500]);

        $this->artisan('fiscal:shipped-paid')->assertSuccessful();

        $this->assertDatabaseCount('fiscal_receipts', 0);
        Http::assertNothingSent();
    }

    public function test_status_reference_is_authoritative_and_text_is_used_when_reference_is_missing(): void
    {
        $canonicalShipped = $this->order();
        $canonicalShipped->update(['status' => 'new']);
        $staleShippedText = $this->order('new');
        $staleShippedText->update(['status' => 'shipped']);
        $withoutReference = $this->order();
        $withoutReference->update(['status_id' => null]);

        $eligibility = app(FiscalizationEligibility::class);
        $selected = $eligibility->shippedPaidCandidates(Order::query())->get();
        $this->assertEqualsCanonicalizing([$canonicalShipped->id, $withoutReference->id], $selected->modelKeys());
        $this->assertTrue($eligibility->hasConfirmedShippedPayment($canonicalShipped->fresh(), 50000));
        $this->assertTrue($eligibility->hasConfirmedShippedPayment($withoutReference->fresh(), 50000));
        $this->assertFalse($eligibility->hasConfirmedShippedPayment($staleShippedText->fresh(), 50000));
    }

    public function test_existing_receipts_are_respected_and_only_remaining_amount_is_fiscalized(): void
    {
        $fullyFiscalized = $this->order();
        $this->receipt($fullyFiscalized, 50000);
        $partiallyFiscalized = $this->order();
        $this->receipt($partiallyFiscalized, 10000);

        $this->artisan('fiscal:shipped-paid')->assertSuccessful();
        $this->artisan('fiscal:shipped-paid')->assertSuccessful();

        $this->assertSame(1, $fullyFiscalized->fiscalReceipts()->count());
        $this->assertSame(2, $partiallyFiscalized->fiscalReceipts()->count());
        $this->assertDatabaseHas('fiscal_receipts', [
            'order_id' => $partiallyFiscalized->id, 'status' => 'success', 'total_amount' => 40000,
        ]);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/receipts/sell')
            && $r['payments'][0]['value'] === 40000);
    }

    public function test_shipped_job_rechecks_current_state_after_loading_stale_order(): void
    {
        foreach (['status', 'payment_status', 'method', 'online_amount'] as $changedField) {
            $order = $this->order(payment: ['provider' => 'wayforpay', 'paid_amount' => 500]);
            $job = new FiscalizeOrderJob($order, amountCents: 50000, automatic: true, shippedPaid: true);
            if ($changedField === 'status') {
                $order->fresh()->update(['status' => 'cancelled', 'status_id' => Status::where('code', 'cancelled')->value('id')]);
            } elseif ($changedField === 'payment_status') {
                $order->fresh()->update(['payment_status' => 'unpaid']);
            } elseif ($changedField === 'method') {
                $order->payment()->update(['method' => 'cod', 'provider' => null]);
            } else {
                $order->items()->update(['total' => 600]);
            }

            $job->handle(app(CheckboxService::class));
        }

        $this->assertDatabaseCount('fiscal_receipts', 0);
        Http::assertNothingSent();
    }

    public function test_disabled_integration_and_closed_window_do_not_fiscalize(): void
    {
        $this->order();
        CheckboxSetting::query()->update(['enabled' => false]);
        $this->artisan('fiscal:shipped-paid')->assertSuccessful();

        CheckboxSetting::query()->update(['enabled' => true]);
        foreach (['2026-10-17 07:00:00', '2026-10-17 23:30:00'] as $time) {
            Carbon::setTestNow(Carbon::parse($time, 'Europe/Kyiv'));
            $this->artisan('fiscal:shipped-paid')->assertSuccessful();
        }

        $this->assertDatabaseCount('fiscal_receipts', 0);
        $this->assertDatabaseCount('fiscal_queue', 0);
        Http::assertNothingSent();
    }

    public function test_new_command_uses_existing_job_even_when_queue_is_disabled(): void
    {
        CheckboxSetting::query()->update(['queue_enabled' => false]);
        $order = $this->order();

        $this->artisan('fiscal:shipped-paid')->assertSuccessful();

        $this->assertDatabaseHas('fiscal_receipts', ['order_id' => $order->id, 'status' => 'success']);
        $this->assertDatabaseCount('fiscal_queue', 0);
        $this->assertSame('shipped', $order->fresh()->status);
    }

    public function test_later_legacy_queue_time_does_not_block_the_new_schedule(): void
    {
        CheckboxSetting::query()->update(['queue_process_time' => '22:30']);
        $order = $this->order();

        $this->artisan('fiscal:shipped-paid')->assertSuccessful();

        $this->assertDatabaseHas('fiscal_receipts', ['order_id' => $order->id, 'status' => 'success']);
        $this->assertDatabaseCount('fiscal_queue', 0);
        $this->assertSame('shipped', $order->fresh()->status);
    }

    public function test_checkbox_failure_preserves_paid_and_shipped_and_retry_reuses_receipt(): void
    {
        $this->fakeCheckbox(400);
        $order = $this->order();

        $this->artisan('fiscal:shipped-paid')->assertExitCode(1);
        $receipt = $order->fiscalReceipts()->firstOrFail();
        $this->assertSame('error', $receipt->status);
        $this->assertStringContainsString('Тимчасова помилка Checkbox', $receipt->error_message);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('shipped', $order->fresh()->status);

        $this->fakeCheckbox();
        $this->artisan('fiscal:shipped-paid')->assertSuccessful();

        $this->assertDatabaseCount('fiscal_receipts', 1);
        $this->assertSame('success', $receipt->fresh()->status);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/receipts/sell') && $r['id'] === $receipt->uuid);
    }

    public function test_shipped_paid_command_is_scheduled_three_times_a_day_in_kyiv_timezone(): void
    {
        $this->app->make(Kernel::class)->bootstrap();
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'fiscal:shipped-paid'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('0 9,15,21 * * *', $event->expression);
        $this->assertSame('Europe/Kyiv', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }
}
