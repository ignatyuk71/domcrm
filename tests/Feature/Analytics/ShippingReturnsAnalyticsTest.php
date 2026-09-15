<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShippingReturnsAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => User::ROLE_OWNER]);
        $this->travelTo(\Carbon\Carbon::parse('2026-09-15 12:00:00', 'Europe/Kyiv'));
    }

    public function test_return_statuses_use_outcome_dates_and_fixed_cost_without_changing_fiscal_revenue(): void
    {
        $returned = $this->shipment('returned', '2026-09-05 10:00:00');
        $this->history($returned, 'refusal', '2026-09-03 14:00:00');
        $this->history($returned, 'refusal', '2026-09-06 14:00:00');
        $this->shipment('returned', '2026-09-07 12:00:00', [], ['delivery_payer' => 'sender', 'delivery_cost' => 275]);
        $this->shipment('delivered_paid', '2026-09-02 10:00:00');
        $this->shipment('delivered_paid', '2026-09-04 10:00:00');
        $this->shipment('delivered_paid', '2026-09-07 10:00:00');

        // Передплата, прибуття, скасування та refund без статусу повернення не є поверненою посилкою.
        foreach (['new', 'shipped', 'delivered', 'cancelled'] as $status) {
            $this->shipment($status, '2026-09-03 12:00:00', ['payment_status' => 'refund']);
        }
        $this->shipment('returned', '2026-08-31 23:59:59', ['created_at' => '2026-09-01 00:00:00']);

        $response = $this->report()->assertOk()
            ->assertJsonPath('shipping_returns.totals.returned', 2)
            ->assertJsonPath('shipping_returns.totals.received', 3)
            ->assertJsonPath('shipping_returns.totals.completed', 5)
            ->assertJsonPath('shipping_returns.totals.return_rate', 40)
            ->assertJsonPath('shipping_returns.totals.estimated_cost', 200)
            ->assertJsonPath('shipping_returns.totals.average_estimated_cost', 100)
            ->assertJsonPath('shipping_returns.cost_basis', 'fixed_estimate')
            ->assertJsonPath('shipping_returns.currency', 'UAH')
            ->assertJsonPath('shipping_returns.estimated_cost_per_return', 100)
            ->assertJsonPath('shipping_returns.trend.returned.2', 1)
            ->assertJsonPath('shipping_returns.trend.returned.4', 0)
            ->assertJsonPath('shipping_returns.trend.returned.5', 0)
            ->assertJsonPath('shipping_returns.trend.estimated_cost.6', 100)
            ->assertJsonPath('fiscal.totals.revenue', 0)
            ->assertJsonPath('fiscal.totals.refunds', 0);
        $this->assertEquals(200, array_sum($response->json('shipping_returns.trend.estimated_cost')));
        $this->assertEquals(2, array_sum($response->json('shipping_returns.trend.returned')));
    }

    public function test_missing_dates_and_tracking_are_excluded_instead_of_using_order_update_time(): void
    {
        $this->shipment('returned', null, ['created_at' => '2026-09-05 12:00:00', 'updated_at' => '2026-09-10 12:00:00']);
        $historical = $this->shipment('returned', null);
        $this->history($historical, 'refusal', '2026-09-04 12:00:00');
        $this->shipment('returned', '2026-09-04 12:00:00', [], ['ttn' => '   ']);
        $this->shipment('delivered_paid', '2026-09-04 12:00:00', [], ['ttn' => null]);

        $this->report()->assertOk()
            ->assertJsonPath('shipping_returns.totals.returned', 1)
            ->assertJsonPath('shipping_returns.totals.estimated_cost', 100)
            ->assertJsonPath('shipping_returns.quality.undated_shipments', 1)
            ->assertJsonPath('shipping_returns.quality.missing_tracking_orders', 2);
    }

    public function test_tracking_duplicates_and_repeat_received_statuses_are_not_counted_twice(): void
    {
        $this->shipment('returned', '2026-09-03 10:00:00', [], ['ttn' => '59000000000001']);
        $this->shipment('returned', '2026-09-07 10:00:00', [], ['ttn' => ' 59000000000001 ']);
        $this->shipment('delivered_paid', '2026-09-01 10:00:00', [], ['ttn' => '59000000000001']);
        $received = $this->shipment('delivered_paid', '2026-09-08 10:00:00');
        $this->history($received, 'received', '2026-09-04 10:00:00');
        $this->history($received, 'received_money', '2026-09-08 10:00:00');

        $this->report()->assertOk()
            ->assertJsonPath('shipping_returns.totals.returned', 1)
            ->assertJsonPath('shipping_returns.totals.received', 1)
            ->assertJsonPath('shipping_returns.totals.return_rate', 50)
            ->assertJsonPath('shipping_returns.trend.returned.2', 1)
            ->assertJsonPath('shipping_returns.trend.returned.6', 0);
        $this->report(['date_from' => '2026-09-04', 'date_to' => '2026-09-04'])->assertOk()
            ->assertJsonPath('shipping_returns.totals.received', 1)
            ->assertJsonPath('shipping_returns.totals.return_rate', 0);
    }

    public function test_business_filters_apply_and_status_dictionary_takes_precedence(): void
    {
        $source = DB::table('order_sources')->insertGetId(['code' => 'returns_test', 'name' => 'Джерело повернень', 'type' => 'order']);
        $manager = User::factory()->create(['role' => User::ROLE_OPERATOR]);
        $returnedStatus = DB::table('statuses')->insertGetId(['type' => 'order', 'code' => 'returned', 'name' => 'Повернення']);
        $matching = ['source_id' => $source, 'manager_id' => $manager->id, 'sale_type' => 'wholesale'];
        $this->shipment('new', '2026-09-03 12:00:00', array_merge($matching, ['status_id' => $returnedStatus]));
        foreach ([['source_id' => null], ['manager_id' => $this->owner->id], ['sale_type' => 'retail'], ['currency' => 'PLN']] as $different) {
            $this->shipment('returned', '2026-09-03 12:00:00', array_merge($matching, $different));
        }
        $this->report(['source_id' => $source, 'manager_id' => $manager->id, 'sale_type' => 'wholesale'])->assertOk()
            ->assertJsonPath('shipping_returns.totals.returned', 1)
            ->assertJsonPath('shipping_returns.totals.estimated_cost', 100);
    }

    public function test_empty_period_and_future_days_have_no_fabricated_percentage_or_returns(): void
    {
        $this->shipment('returned', '2026-09-16 00:00:00');
        $this->report(['date_to' => '2026-09-30'])->assertOk()
            ->assertJsonPath('shipping_returns.totals.returned', 0)
            ->assertJsonPath('shipping_returns.totals.return_rate', null)
            ->assertJsonPath('shipping_returns.totals.average_estimated_cost', null)
            ->assertJsonPath('shipping_returns.totals.estimated_cost', 0)
            ->assertJsonPath('shipping_returns.trend.returned.14', 0)
            ->assertJsonPath('shipping_returns.trend.returned.15', null)
            ->assertJsonPath('shipping_returns.trend.estimated_cost.29', null);
        $this->report(['date_from' => '2026-09-20', 'date_to' => '2026-09-21'])->assertOk()
            ->assertJsonPath('shipping_returns.trend.returned', [null, null])
            ->assertJsonPath('shipping_returns.totals.completed', 0);
    }

    private function report(array $filters = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner)->getJson('/api/analytics/sales?'.http_build_query(array_merge([
            'date_from' => '2026-09-01', 'date_to' => '2026-09-15', 'fiscal_only' => 1, 'fresh' => 1,
        ], $filters)));
    }

    private function shipment(string $status, ?string $changedAt, array $orderAttributes = [], array $deliveryAttributes = []): int
    {
        static $number = 50000;
        $order = DB::table('orders')->insertGetId(array_merge([
            'order_number' => (string) ++$number, 'status' => $status, 'status_changed_at' => $changedAt,
            'currency' => 'UAH', 'sale_type' => 'retail', 'payment_status' => 'unpaid',
            'manager_id' => $this->owner->id, 'created_at' => '2026-08-10 10:00:00', 'updated_at' => '2026-09-15 10:00:00',
        ], $orderAttributes));

        return DB::table('order_deliveries')->insertGetId(array_merge([
            'order_id' => $order, 'carrier' => 'nova_poshta', 'delivery_type' => 'warehouse',
            'ttn' => '590000000'.$order, 'delivery_payer' => 'recipient',
            'created_at' => '2026-08-10 10:00:00', 'updated_at' => '2026-09-15 10:00:00',
        ], $deliveryAttributes));
    }

    private function history(int $delivery, string $status, string $date): void
    {
        DB::table('order_delivery_status_histories')->insert([
            'order_delivery_id' => $delivery, 'status_code' => $status, 'entered_at' => $date,
        ]);
    }
}
