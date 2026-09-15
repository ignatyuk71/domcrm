<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SenderPaidDeliveryAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_only_uses_current_ttn_api_price_and_payer_with_exact_cents(): void
    {
        Http::preventStrayRequests();
        $first = $this->delivery(['np_document_cost' => 96.25, 'delivery_payer' => 'recipient']);
        $this->delivery(['np_document_cost' => 90]);
        $this->delivery(['np_document_cost' => 0]);
        $this->delivery(['np_document_cost' => 200, 'np_payer_type' => 'recipient']);
        $this->delivery(['np_document_cost' => 500, 'np_cost_status_code' => '1']);
        $this->delivery(['np_document_cost' => null, 'delivery_cost' => 1500]);
        $this->delivery(['np_document_cost' => 100, 'np_document_date' => '2026-08-31 23:59:59']);

        $response = $this->report()->assertOk()
            ->assertJsonPath('sender_delivery.totals.shipments', 5)
            ->assertJsonPath('sender_delivery.totals.sent', 4)
            ->assertJsonPath('sender_delivery.totals.priced', 3)
            ->assertJsonPath('sender_delivery.totals.known_cost', 186.25)
            ->assertJsonPath('sender_delivery.totals.unknown_cost', 1)
            ->assertJsonPath('sender_delivery.totals.not_sent', 1)
            ->assertJsonPath('sender_delivery.cost_source', 'nova_poshta.DocumentCost')
            ->assertJsonPath('fiscal.totals.revenue', 0)
            ->assertJsonPath('shipping_returns.totals.estimated_cost', 0);
        $row = collect($response->json('sender_delivery.rows.data'))->firstWhere('order_id', $first);
        $this->assertSame(96.25, $row['cost']);
        $this->assertSame('/orders/'.$first.'/edit', $row['order_url']);
        Http::assertNothingSent();
    }

    public function test_unknown_and_stale_snapshots_do_not_become_zero_or_previous_ttn_prices(): void
    {
        $this->delivery(['np_document_cost' => null]);
        $this->delivery(['np_document_cost' => 999, 'np_cost_ttn' => 'old-ttn']);
        $this->report()->assertOk()
            ->assertJsonPath('sender_delivery.totals.known_cost', null)
            ->assertJsonPath('sender_delivery.rows.data.0.cost', null)
            ->assertJsonPath('sender_delivery.rows.data.0.payer_verified', false)
            ->assertJsonPath('sender_delivery.quality.unverified_payer', 1);
    }

    public function test_duplicates_are_deduplicated_and_pagination_keeps_full_totals(): void
    {
        $this->delivery(['ttn' => '59000000000111', 'np_cost_ttn' => '59000000000111', 'np_document_cost' => 90]);
        $this->delivery(['ttn' => '59000000000111', 'np_cost_ttn' => '59000000000111', 'np_document_cost' => 90]);
        for ($i = 0; $i < 10; $i++) {
            $this->delivery(['np_document_cost' => 10.25]);
        }
        $this->report(['sender_delivery_page' => 2])->assertOk()
            ->assertJsonPath('sender_delivery.totals.shipments', 11)
            ->assertJsonPath('sender_delivery.totals.known_cost', 192.5)
            ->assertJsonPath('sender_delivery.rows.current_page', 2)
            ->assertJsonPath('sender_delivery.rows.last_page', 2)
            ->assertJsonCount(1, 'sender_delivery.rows.data');
    }

    public function test_unchecked_waybills_are_not_presented_as_free_shipping(): void
    {
        $this->delivery(['np_document_cost' => null, 'np_cost_ttn' => null, 'np_payer_type' => null, 'np_cost_checked_at' => null]);
        $this->report()->assertOk()
            ->assertJsonPath('sender_delivery.totals.known_cost', null)
            ->assertJsonPath('sender_delivery.quality.missing_prices', 1);
    }

    public function test_common_filters_apply_and_recipient_returns_do_not_enter_sender_block(): void
    {
        $source = DB::table('order_sources')->insertGetId(['type' => 'order', 'code' => 'sender-test', 'name' => 'Джерело']);
        $manager = User::factory()->create(['role' => User::ROLE_OPERATOR]);
        $this->delivery(['np_document_cost' => 90], ['source_id' => $source, 'manager_id' => $manager->id, 'sale_type' => 'wholesale']);
        $this->delivery(['np_document_cost' => 200]);
        $this->delivery(['np_document_cost' => 96.25, 'np_payer_type' => 'recipient', 'np_cost_status_code' => '102'], ['status' => 'returned']);
        $this->report(['source_id' => $source, 'manager_id' => $manager->id, 'sale_type' => 'wholesale'])->assertOk()
            ->assertJsonPath('sender_delivery.totals.shipments', 1)
            ->assertJsonPath('sender_delivery.totals.known_cost', 90);
        $this->report(['sender_delivery_page' => 0])->assertUnprocessable();
        $this->actingAs($manager)->getJson('/api/analytics/sales?fiscal_only=1')->assertForbidden();
    }

    private function report(array $filters = []): \Illuminate\Testing\TestResponse
    {
        $owner = User::factory()->create(['role' => User::ROLE_OWNER]);

        return $this->actingAs($owner)->getJson('/api/analytics/sales?'.http_build_query(array_merge([
            'date_from' => '2026-09-01', 'date_to' => '2026-09-15', 'fiscal_only' => 1, 'fresh' => 1,
        ], $filters)));
    }

    private function delivery(array $fields, array $orderFields = []): int
    {
        static $number = 70000;
        $order = DB::table('orders')->insertGetId(array_merge(['order_number' => (string) ++$number, 'status' => 'delivered_paid', 'currency' => 'UAH', 'sale_type' => 'retail', 'created_at' => '2026-07-01 10:00:00'], $orderFields));
        $ttn = '59000000'.$order;
        DB::table('order_deliveries')->insert(array_merge([
            'order_id' => $order, 'carrier' => 'nova_poshta', 'delivery_type' => 'warehouse', 'delivery_payer' => 'sender',
            'ttn' => $ttn, 'np_cost_ttn' => $ttn, 'np_payer_type' => 'sender', 'np_cost_status_code' => '9',
            'np_document_date' => '2026-09-10 12:00:00', 'np_cost_checked_at' => '2026-09-15 12:00:00', 'created_at' => '2026-09-10 12:00:00',
        ], $fields));

        return $order;
    }
}
