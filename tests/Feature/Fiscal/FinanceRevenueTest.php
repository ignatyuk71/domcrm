<?php

namespace Tests\Feature\Fiscal;

use App\Models\CheckboxSetting;
use App\Models\FiscalReceipt;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinanceRevenueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Europe/Kyiv']);
        Carbon::setTestNow(Carbon::parse('2026-10-21 22:00:00', 'Europe/Kyiv'));
        CheckboxSetting::create(['enabled' => false]);
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['id' => 1, 'role' => User::ROLE_OWNER]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_today_and_hourly_revenue_subtract_successful_refunds(): void
    {
        $this->receipt('sell', 'success', 2106200, '2026-10-21 13:15:00');
        $refund = $this->receipt('return', 'success', 427500, '2026-10-21 21:17:31');
        $this->receipt('return', 'error', 90000, '2026-10-21 21:30:00');
        $this->receipt('sell', 'pending', 60000, '2026-10-21 21:40:00');
        $this->receipt('sell', 'processing', 70000, '2026-10-21 21:41:00');
        $this->receipt('sell', 'error', 80000, '2026-10-21 21:42:00');
        $this->receipt('return', 'canceled', 100000, '2026-10-21 21:43:00');
        $this->receipt('service_in', 'success', 500000, '2026-10-21 21:45:00');
        $this->receipt('service_out', 'success', 300000, '2026-10-21 21:50:00');

        $response = $this->getJson('/api/finance/checkbox')->assertOk()
            ->assertJsonPath('receipts.daily_total', 1678700)
            ->assertJsonPath('receipts.hourly_amounts.13', 2106200)
            ->assertJsonPath('receipts.hourly_amounts.21', -427500)
            ->assertJsonPath('receipts.hourly_counts.21', 8)
            ->assertJsonCount(9, 'receipts.items');

        $this->assertSame(1678700, array_sum($response->json('receipts.hourly_amounts')));
        $historyRefund = collect($response->json('receipts.items'))->firstWhere('id', $refund->id);
        $this->assertSame(427500, $historyRefund['total_amount']);
        $this->assertSame('return', $historyRefund['type']);
        Http::assertNothingSent();
    }

    public function test_refund_of_a_previous_day_sale_can_make_today_revenue_negative(): void
    {
        $this->receipt('sell', 'success', 427500, '2026-10-20 23:59:59');
        $this->receipt('return', 'success', 427500, '2026-10-21 00:00:00');
        $this->receipt('sell', 'success', 10000, '2026-10-21 00:30:00');
        $this->receipt('sell', 'success', 90000, '2026-10-22 00:00:00');

        $this->getJson('/api/finance/checkbox')->assertOk()
            ->assertJsonPath('receipts.daily_total', -417500)
            ->assertJsonPath('receipts.hourly_amounts.0', -417500)
            ->assertJsonCount(2, 'receipts.items');
    }

    public function test_history_limit_does_not_limit_revenue_totals(): void
    {
        $this->receipt('return', 'success', 10000, '2026-10-21 08:00:00');
        $order = Order::create(['order_number' => 'finance-many', 'currency' => 'UAH']);
        $rows = [];
        for ($i = 0; $i < 251; $i++) {
            $rows[] = [
                'order_id' => $order->id, 'uuid' => (string) Str::uuid(),
                'payload_hash' => (string) Str::uuid(), 'type' => 'sell', 'status' => 'success',
                'total_amount' => 1000, 'created_at' => '2026-10-21 10:00:00',
                'updated_at' => '2026-10-21 10:00:00',
            ];
        }
        FiscalReceipt::insert($rows);

        $this->getJson('/api/finance/checkbox')->assertOk()
            ->assertJsonPath('receipts.daily_total', 241000)
            ->assertJsonPath('receipts.hourly_amounts.8', -10000)
            ->assertJsonPath('receipts.hourly_amounts.10', 251000)
            ->assertJsonPath('receipts.payment_breakdown.other_cents', 241000)
            ->assertJsonCount(200, 'receipts.items');
    }

    public function test_finance_data_remains_restricted_to_the_owner(): void
    {
        foreach ([User::ROLE_OPERATOR, User::ROLE_PACKER] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->getJson('/api/finance/checkbox')->assertForbidden();
        }
    }

    private function receipt(string $type, string $status, int $amount, string $at): FiscalReceipt
    {
        $order = Order::create(['order_number' => 'finance-'.Str::uuid(), 'currency' => 'UAH']);
        $receipt = $order->fiscalReceipts()->make([
            'type' => $type, 'status' => $status, 'total_amount' => $amount,
            'payload_hash' => (string) Str::uuid(),
        ]);
        $receipt->created_at = Carbon::parse($at, 'Europe/Kyiv');
        $receipt->updated_at = $receipt->created_at;
        $receipt->save();

        return $receipt;
    }
}
