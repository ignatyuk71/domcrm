<?php

namespace Tests\Feature\Fiscal;

use App\Models\CheckboxSetting;
use App\Models\FiscalReceipt;
use App\Models\Order;
use App\Models\User;
use App\Services\CheckboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManualFiscalShiftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CheckboxSetting::create([
            'api_url' => 'https://api.checkbox.in.ua/api/v1',
            'license_key' => 'TEST-LICENSE',
            'login' => 'test-login',
            'password' => 'test-password',
            'enabled' => true,
        ]);

        Http::preventStrayRequests();
    }

    private function user(string $role = User::ROLE_OWNER): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function order(): Order
    {
        $order = Order::create([
            'order_number' => 'MANUAL-' . Str::random(12),
            'status' => 'new',
            'payment_status' => 'unpaid',
            'currency' => 'UAH',
        ]);
        $order->items()->create([
            'product_title' => 'Капці', 'sku' => 'MANUAL-1',
            'qty' => 1, 'price' => 500, 'total' => 500,
        ]);
        $order->payment()->create(['method' => 'card', 'currency' => 'UAH', 'prepay_amount' => 100]);

        return $order;
    }

    private function fakeShift(?array $shift, int $status = 200): void
    {
        Http::fake([
            '*cashier/signin' => Http::response(['access_token' => 'test-token'], 200),
            '*cashier/shift' => Http::response($shift, $status),
            '*receipts/sell' => Http::response([
                'id' => (string) Str::uuid(), 'status' => 'DONE', 'fiscal_code' => 'FC-MANUAL',
            ], 200),
        ]);
    }

    private function assertNoFiscalWrites(): void
    {
        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && (str_contains($request->url(), '/shifts') || str_contains($request->url(), '/receipts/')));
        $this->assertDatabaseCount('fiscal_queue', 0);
    }

    public function test_owner_and_operator_can_read_only_the_shift_availability(): void
    {
        $this->fakeShift(['status' => 'OPENED']);
        foreach ([User::ROLE_OWNER, User::ROLE_OPERATOR] as $role) {
            $response = $this->actingAs($this->user($role))->getJson('/api/fiscal/availability')
                ->assertOk()->assertExactJson([
                    'available' => true,
                    'shift_status' => 'OPENED',
                    'code' => null,
                    'message' => 'Каса відкрита',
                ]);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }

        $this->assertNoFiscalWrites();
    }

    public static function unavailableShifts(): array
    {
        return [
            'closed' => [['status' => 'CLOSED'], 200, 'CLOSED', 'Каса закрита'],
            'empty' => [null, 200, 'CLOSED', 'Каса закрита'],
            'not found' => [null, 404, 'CLOSED', 'Каса закрита'],
            'opening' => [['status' => 'OPENING'], 200, 'OPENING', 'Каса відкривається'],
            'closing' => [['status' => 'CLOSING'], 200, 'CLOSING', 'Каса закривається'],
            'unknown status' => [['status' => 'CREATED'], 200, 'UNKNOWN', 'Не вдалося перевірити стан каси. Повторіть перевірку.'],
            'provider error' => [[], 503, 'UNKNOWN', 'Не вдалося перевірити стан каси. Повторіть перевірку.'],
        ];
    }

    #[DataProvider('unavailableShifts')]
    public function test_unconfirmed_open_shift_is_unavailable_without_opening_it(?array $shift, int $httpStatus, string $expectedStatus, string $message): void
    {
        $this->fakeShift($shift, $httpStatus);

        $this->actingAs($this->user())->getJson('/api/fiscal/availability')
            ->assertOk()->assertJsonPath('available', false)
            ->assertJsonPath('shift_status', $expectedStatus)
            ->assertJsonPath('message', $message);

        $this->assertNoFiscalWrites();
    }

    public function test_availability_keeps_existing_role_restrictions(): void
    {
        $this->getJson('/api/fiscal/availability')->assertUnauthorized();
        $this->actingAs($this->user(User::ROLE_PACKER))->getJson('/api/fiscal/availability')->assertForbidden();
        Http::assertNothingSent();
    }

    public static function manualActions(): array
    {
        return [
            'sale' => ['sell'],
            'prepayment' => ['prepay'],
            'refund' => ['return'],
        ];
    }

    #[DataProvider('manualActions')]
    public function test_closed_shift_rejects_manual_action_without_queue_or_receipt(string $type): void
    {
        $order = $this->order();
        if ($type === 'return') {
            $order->fiscalReceipts()->create([
                'type' => FiscalReceipt::TYPE_SELL, 'status' => FiscalReceipt::STATUS_SUCCESS,
                'total_amount' => 50000, 'payload_hash' => 'existing-sale',
            ]);
        }
        $receiptCount = $order->fiscalReceipts()->count();
        $this->fakeShift(['status' => 'CLOSED']);
        $url = $type === 'return' ? '/refund' : '/fiscalize';

        $this->actingAs($this->user())->postJson('/api/orders/' . $order->id . $url, ['type' => $type])
            ->assertStatus(409)->assertJsonPath('code', 'fiscal_shift_closed')
            ->assertJsonPath('message', 'Каса закрита');

        $this->assertSame($receiptCount, $order->fiscalReceipts()->count());
        $this->assertSame('new', $order->fresh()->status);
        $this->assertNoFiscalWrites();
    }

    public function test_failed_shift_check_rejects_direct_manual_post(): void
    {
        $order = $this->order();
        $this->fakeShift(null, 503);

        $this->actingAs($this->user())->postJson('/api/orders/' . $order->id . '/fiscalize')
            ->assertStatus(503)->assertJsonPath('available', false)
            ->assertJsonPath('code', 'fiscal_shift_unavailable');

        $this->assertDatabaseCount('fiscal_receipts', 0);
        $this->assertNoFiscalWrites();
    }

    public function test_network_error_is_unknown_and_never_opens_shift(): void
    {
        Http::fake([
            '*cashier/signin' => Http::response(['access_token' => 'test-token'], 200),
            '*cashier/shift' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Test connection error'),
        ]);

        $this->actingAs($this->user())->getJson('/api/fiscal/availability')
            ->assertOk()->assertJsonPath('available', false)->assertJsonPath('shift_status', 'UNKNOWN');
        $this->postJson('/api/orders/' . $this->order()->id . '/fiscalize')
            ->assertStatus(503)->assertJsonPath('code', 'fiscal_shift_unavailable');

        $this->assertNoFiscalWrites();
    }

    public function test_shift_is_checked_again_before_posting_receipt(): void
    {
        $order = $this->order();
        Http::fake([
            '*cashier/signin' => Http::response(['access_token' => 'test-token'], 200),
            '*cashier/shift' => Http::sequence()
                ->push(['status' => 'OPENED'], 200)
                ->push(['status' => 'CLOSED'], 200),
        ]);

        $this->actingAs($this->user())->postJson('/api/orders/' . $order->id . '/fiscalize')
            ->assertStatus(409)->assertJsonPath('code', 'fiscal_shift_closed');

        $this->assertNoFiscalWrites();
        $this->assertSame('new', $order->fresh()->status);
        $this->assertSame(FiscalReceipt::STATUS_ERROR, $order->fiscalReceipts()->sole()->status);
        $this->assertSame('Каса закрита', $order->fiscalReceipts()->sole()->error_message);
    }

    public function test_open_shift_keeps_manual_prepayment_amount(): void
    {
        $this->fakeShift(['status' => 'OPENED']);
        $order = $this->order();

        $this->actingAs($this->user())->postJson('/api/orders/' . $order->id . '/fiscalize', ['type' => 'prepay'])
            ->assertOk()->assertJsonPath('status', FiscalReceipt::STATUS_SUCCESS);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/receipts/sell')
            && $request['payments'][0]['value'] === 10000);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/shifts'));
        $this->assertSame(10000, $order->fiscalReceipts()->sole()->total_amount);
        $this->assertDatabaseCount('fiscal_queue', 0);
    }

    public function test_default_service_behavior_for_automatic_fiscalization_still_opens_shift(): void
    {
        $order = $this->order();
        Http::fake([
            '*cashier/signin' => Http::response(['access_token' => 'test-token'], 200),
            '*cashier/shift' => Http::response(['status' => 'CLOSED'], 200),
            '*shifts' => Http::response(['status' => 'OPENED'], 200),
            '*receipts/sell' => Http::response(['id' => (string) Str::uuid(), 'status' => 'DONE'], 200),
        ]);

        $receipt = app(CheckboxService::class)->createReceipt($order);

        $this->assertSame('DONE', $receipt['status']);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/shifts'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/receipts/sell')
            && $request['payments'][0]['value'] === 50000);
    }
}
