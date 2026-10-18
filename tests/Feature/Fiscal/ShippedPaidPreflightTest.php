<?php

namespace Tests\Feature\Fiscal;

use App\Jobs\FiscalizeOrderJob;
use App\Models\CheckboxSetting;
use App\Models\FiscalReceipt;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Status;
use App\Services\CheckboxService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShippedPaidPreflightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Europe/Kyiv']);
        Carbon::setTestNow(Carbon::parse('2026-10-18 15:00:00', 'Europe/Kyiv'));
        foreach (['new', 'shipped', 'delivered', 'delivered_paid', 'cancelled', 'returned'] as $code) {
            Status::create(['type' => 'order', 'code' => $code, 'name' => $code]);
        }
        CheckboxSetting::create([
            'api_url' => 'https://api.checkbox.in.ua/api/v1',
            'license_key' => 'TEST', 'login' => 'test', 'password' => 'test',
            'enabled' => true, 'queue_enabled' => true,
            'open_time' => '08:00', 'close_time' => '23:00', 'queue_process_time' => '08:30',
            'last_opened_at' => now()->startOfDay()->addHours(8),
        ]);

        // Усі зовнішні звернення підмінено; реальна каса недоступна тестам.
        $this->fakeCheckbox();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeCheckbox(string $receiptStatus = 'DONE', ?array $existingReceipt = null): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($receiptStatus, $existingReceipt) {
            if (str_ends_with($request->url(), '/cashier/signin')) {
                return Http::response(['access_token' => 'test-token']);
            }
            if (str_ends_with($request->url(), '/cashier/shift')) {
                return Http::response(['status' => 'OPENED']);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/receipts/')) {
                return $existingReceipt
                    ? Http::response($existingReceipt)
                    : Http::response(['message' => 'Не знайдено'], 404);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/receipts/sell')) {
                return Http::response([
                    'id' => $request['id'],
                    'status' => $receiptStatus,
                    'fiscal_code' => $receiptStatus === 'DONE' ? 'FC-PREFLIGHT' : null,
                    'message' => $receiptStatus === 'DONE' ? null : 'Checkbox не завершив створення чека',
                ]);
            }

            throw new \RuntimeException('Неочікуваний запит у тесті: '.$request->url());
        });
    }

    private function order(string $status = 'shipped', bool $online = false): Order
    {
        $order = Order::create([
            'order_number' => 'PF-'.Str::ulid(),
            'status' => $status,
            'status_id' => Status::where('code', $status)->value('id'),
            'payment_status' => 'paid',
            'currency' => 'UAH',
        ]);
        $order->items()->create([
            'product_title' => 'Капці', 'sku' => 'PREFLIGHT-38', 'size' => '38',
            'price' => 399, 'qty' => 1, 'total' => 399,
        ]);
        OrderPayment::create([
            'order_id' => $order->id,
            'method' => 'card',
            'provider' => $online ? 'wayforpay' : null,
            'paid_amount' => $online ? 399 : null,
            'currency' => 'UAH',
            'transaction_id' => $online ? 'txn-'.$order->id : null,
        ]);

        return $order;
    }

    private function assertOneSuccessfulReceipt(Order $order, string $deliveryStatus): void
    {
        $this->assertSame(1, $order->fiscalReceipts()->count());
        $this->assertDatabaseHas('fiscal_receipts', [
            'order_id' => $order->id,
            'type' => FiscalReceipt::TYPE_SELL,
            'status' => FiscalReceipt::STATUS_SUCCESS,
            'total_amount' => 39900,
        ]);
        $this->assertSame($deliveryStatus, $order->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/receipts/sell')
            && $request['payments'][0]['value'] === 39900);
    }

    public function test_paid_bank_order_already_at_post_office_is_fiscalized_once(): void
    {
        // Випадок зі скриншота №7475: уже у відділенні, оплачено на рахунок, 399 грн.
        $order = $this->order('delivered');

        $this->artisan('fiscal:shipped-paid')->assertSuccessful();
        $this->artisan('fiscal:shipped-paid')->assertSuccessful();

        $this->assertOneSuccessfulReceipt($order, 'delivered');
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/receipts/sell')));
        $this->assertDatabaseCount('order_status_changes', 0);
    }

    public function test_paid_wayforpay_order_already_at_post_office_is_fiscalized_once(): void
    {
        $order = $this->order('delivered', online: true);

        $this->artisan('fiscal:shipped-paid')->assertSuccessful();
        $this->artisan('fiscal:shipped-paid')->assertSuccessful();

        $this->assertOneSuccessfulReceipt($order, 'delivered');
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/receipts/sell')));
        $this->assertDatabaseCount('order_status_changes', 0);
    }

    public function test_progress_from_shipped_to_post_office_before_job_does_not_lose_receipt(): void
    {
        $order = $this->order();
        $job = new FiscalizeOrderJob($order, amountCents: 39900, automatic: true, shippedPaid: true);
        $order->fresh()->update([
            'status' => 'delivered',
            'status_id' => Status::where('code', 'delivered')->value('id'),
        ]);

        $deliveryChangeCount = DB::table('order_status_changes')->count();

        $job->handle(app(CheckboxService::class));

        $this->assertOneSuccessfulReceipt($order, 'delivered');
        $this->assertDatabaseCount('order_status_changes', $deliveryChangeCount);
    }

    public function test_pickup_and_legacy_handlers_do_not_repeat_early_receipts(): void
    {
        foreach ([false, true] as $online) {
            $order = $this->order(online: $online);
            $this->artisan('fiscal:shipped-paid')->assertSuccessful();
            $this->assertOneSuccessfulReceipt($order, 'shipped');

            $order->refresh()->update([
                'status' => 'delivered_paid',
                'status_id' => Status::where('code', 'delivered_paid')->value('id'),
            ]);
        }

        // Забрану посилку обробляють старий крон і наявна черга; нового чека не має бути.
        foreach ([1, 2] as $attempt) {
            $this->artisan('fiscal:delivered')->assertSuccessful();
            $this->artisan('fiscal:shift-manager')->assertSuccessful();
            $this->artisan('fiscal:shipped-paid')->assertSuccessful();
        }

        $this->assertDatabaseCount('fiscal_receipts', 2);
        $this->assertSame(79800, (int) FiscalReceipt::where('status', 'success')->sum('total_amount'));
        $this->assertDatabaseCount('fiscal_queue', 0);
        $this->assertCount(2, Http::recorded(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/receipts/sell')));
    }

    public function test_scheduler_really_runs_only_at_three_agreed_kyiv_times(): void
    {
        $this->app->make(Kernel::class)->bootstrap();
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'fiscal:shipped-paid'));
        $this->assertCount(1, $events);
        $event = $events->first();

        foreach ([
            '08:59' => false, '09:00' => true, '09:01' => false,
            '14:59' => false, '15:00' => true, '15:01' => false,
            '20:59' => false, '21:00' => true, '21:01' => false,
            '08:00' => false, '14:00' => false, '20:00' => false,
        ] as $time => $expected) {
            Carbon::setTestNow(Carbon::parse('2026-10-18 '.$time.':00', 'Europe/Kyiv'));
            $this->assertSame($expected, $event->isDue($this->app), 'Час Києва: '.$time);
        }

        Http::assertNothingSent();
    }

    private function makeUnsuccessfulReceipt(string $checkboxStatus): array
    {
        $this->fakeCheckbox($checkboxStatus);
        $order = $this->order();

        // HTTP 200 не означає успішний чек: незавершений результат має бути видимим збоєм крона.
        $this->artisan('fiscal:shipped-paid')->assertExitCode(1);
        $receipt = $order->fiscalReceipts()->firstOrFail();

        $this->assertSame(FiscalReceipt::STATUS_ERROR, $receipt->status);
        $this->assertSame(39900, $receipt->total_amount);
        $this->assertSame('shipped', $order->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);

        return [$order, $receipt];
    }

    public function test_checkbox_error_status_is_reported_and_retry_reuses_receipt_uuid(): void
    {
        [$order, $receipt] = $this->makeUnsuccessfulReceipt('ERROR');
        $uuid = $receipt->uuid;

        $this->fakeCheckbox();
        $this->artisan('fiscal:shipped-paid')->assertSuccessful();

        $this->assertOneSuccessfulReceipt($order, 'shipped');
        $this->assertSame($uuid, $receipt->fresh()->uuid);
        $this->assertSame(FiscalReceipt::STATUS_SUCCESS, $receipt->fresh()->status);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_ends_with($request->url(), '/receipts/'.$uuid));
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/receipts/sell')
            && $request['id'] === $uuid);
    }

    public function test_pending_checkbox_receipt_is_reported_and_later_recovered_without_second_post(): void
    {
        [$order, $receipt] = $this->makeUnsuccessfulReceipt('PENDING');
        $uuid = $receipt->uuid;

        // Checkbox завершив попередню спробу: підхоплюємо готовий чек за тим самим UUID.
        $this->fakeCheckbox(existingReceipt: [
            'id' => $uuid, 'status' => 'DONE', 'fiscal_code' => 'FC-RECOVERED',
        ]);
        $this->artisan('fiscal:shipped-paid')->assertSuccessful();

        $this->assertSame(1, $order->fiscalReceipts()->count());
        $this->assertSame($uuid, $receipt->fresh()->uuid);
        $this->assertSame(FiscalReceipt::STATUS_SUCCESS, $receipt->fresh()->status);
        $this->assertSame(39900, $receipt->fresh()->total_amount);
        $this->assertSame('shipped', $order->fresh()->status);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_ends_with($request->url(), '/receipts/'.$uuid));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/receipts/sell'));
    }

    public function test_increased_order_total_during_receipt_request_prevents_false_success(): void
    {
        $order = $this->order();
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*cashier/signin' => Http::response(['access_token' => 'test-token']),
            '*cashier/shift' => Http::response(['status' => 'OPENED']),
            '*receipts/sell' => function ($request) use ($order) {
                // Поки каса створює чек на 399 грн, менеджер змінює суму замовлення на 500 грн.
                $order->items()->update(['price' => 500, 'total' => 500]);

                return Http::response([
                    'id' => $request['id'], 'status' => 'DONE', 'fiscal_code' => 'FC-TOTAL-CHANGED',
                ]);
            },
        ]);

        $this->artisan('fiscal:shipped-paid')->assertExitCode(1);

        // Чек зберігаємо, але повністю фіскалізованим змінене замовлення не вважаємо.
        $this->assertOneSuccessfulReceipt($order, 'shipped');
        $this->assertSame(50000, (int) round($order->items()->sum('total') * 100));
        $this->assertDatabaseCount('order_status_changes', 0);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/receipts/sell')));
    }

    public function test_busy_order_lock_is_reported_and_next_run_succeeds_after_release(): void
    {
        $order = $this->order();
        $lock = Cache::lock('fiscal:order:'.$order->id.':'.FiscalReceipt::TYPE_SELL, 180);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('fiscal:shipped-paid')->assertExitCode(1);
            $this->assertDatabaseCount('fiscal_receipts', 0);
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }

        $this->artisan('fiscal:shipped-paid')->assertSuccessful();
        $this->assertOneSuccessfulReceipt($order, 'shipped');
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/receipts/sell')));
    }
}
