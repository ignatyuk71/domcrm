<?php

namespace Tests\Feature\Analytics;

use App\Models\FiscalReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_receives_financial_summary_breakdowns_and_audit(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
        $manager = User::factory()->create(['role' => User::ROLE_OPERATOR]);
        [$sourceId, $doneId, $newId, $cancelledId] = $this->dictionaryRows();

        $retailOrder = $this->order($sourceId, $manager->id, $doneId, 'delivered_paid', 'retail', 'paid', '2026-08-03 10:00:00');
        $wholesaleOrder = $this->order($sourceId, $manager->id, $newId, 'new', 'wholesale', 'unpaid', '2026-08-04 10:00:00');
        $cancelledOrder = $this->order($sourceId, $manager->id, $cancelledId, 'cancelled', 'retail', 'unpaid', '2026-08-05 10:00:00');

        $this->item($retailOrder, 'Сукня', 2, 100, 60);
        $this->item($wholesaleOrder, 'Футболка', 3, 80, 50);
        $this->item($cancelledOrder, 'Неуспішний товар', 1, 500, 250);

        $response = $this->actingAs($owner)->getJson('/api/analytics/sales?date_from=2026-08-01&date_to=2026-08-31&currency=UAH&scope=valid');

        $response->assertOk()
            ->assertJsonPath('kpis.revenue.value', 440)
            ->assertJsonPath('kpis.cogs.value', 270)
            ->assertJsonPath('kpis.gross_profit.value', 170)
            ->assertJsonPath('kpis.orders.value', 2)
            ->assertJsonPath('kpis.units.value', 5)
            ->assertJsonPath('kpis.paid_revenue.value', 200)
            ->assertJsonPath('kpis.cancellations.count', 1)
            ->assertJsonPath('audit.total', 2)
            ->assertJsonPath('sale_types.0.key', 'wholesale')
            ->assertJsonStructure([
                'meta', 'filters', 'kpis', 'trend', 'sale_types', 'sources',
                'top_products', 'managers', 'statuses', 'insights', 'audit',
            ]);

        $this->assertEqualsWithDelta(38.6, $response->json('kpis.gross_margin.value'), 0.1);
    }

    public function test_sale_type_filter_and_missing_cost_are_handled_without_inflating_profit(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
        [$sourceId, $doneId] = $this->dictionaryRows();

        $order = $this->order($sourceId, $owner->id, $doneId, 'delivered_paid', 'wholesale', 'paid', '2026-08-10 10:00:00');
        $this->item($order, 'Відомий кост', 2, 100, 70);
        $this->item($order, 'Без закупки', 1, 300, null);

        $response = $this->actingAs($owner)->getJson('/api/analytics/sales?date_from=2026-08-01&date_to=2026-08-31&currency=UAH&scope=all&sale_type=wholesale');

        $response->assertOk()
            ->assertJsonPath('kpis.revenue.value', 500)
            ->assertJsonPath('kpis.cogs.value', 140)
            ->assertJsonPath('kpis.gross_profit.value', 60)
            ->assertJsonPath('kpis.cost_coverage.value', 40)
            ->assertJsonPath('kpis.cost_coverage.missing_lines', 1)
            ->assertJsonPath('audit.data.0.has_missing_cost', true);
    }

    public function test_analytics_are_owner_only(): void
    {
        $operator = User::factory()->create(['role' => User::ROLE_OPERATOR]);

        $this->actingAs($operator)->get('/analytics')->assertForbidden();
        $this->actingAs($operator)->getJson('/api/analytics/sales')->assertForbidden();
    }

    public function test_owner_can_export_filtered_audit_as_csv(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
        [$sourceId, $doneId] = $this->dictionaryRows();
        $order = $this->order($sourceId, $owner->id, $doneId, 'delivered_paid', 'retail', 'paid', '2026-08-12 10:00:00');
        $this->item($order, 'Тестовий товар', 1, 250, 100);

        $response = $this->actingAs($owner)->get('/analytics/export?date_from=2026-08-01&date_to=2026-08-31&currency=UAH&scope=valid');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('Instagram', $response->streamedContent());
    }

    public function test_fiscal_revenue_uses_receipt_dates_and_amounts_not_order_dates_or_current_statuses(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
        [$source, $done, $new, $cancelled] = $this->dictionaryRows();
        $oldOrder = $this->order($source, $owner->id, $cancelled, 'cancelled', 'retail', 'refund', '2026-07-02 10:00:00');
        $inTransit = $this->order($source, $owner->id, $new, 'new', 'retail', 'unpaid', '2026-08-02 10:00:00');
        $this->item($oldOrder, 'Замовлення з поверненням', 1, 1000, 400);
        $this->item($inTransit, 'Ще в дорозі', 1, 9000, 1000);
        $this->receipt($oldOrder, 'sell', 20000, '2026-08-03T08:00:00+00:00', 'CASH');
        $this->receipt($oldOrder, 'sell', 80000, '2026-08-04T08:00:00+00:00');
        $this->receipt($oldOrder, 'return', 30000, '2026-08-05T08:00:00+00:00');
        $this->receipt($inTransit, 'sell', 900000, '2026-08-02T08:00:00+00:00', 'CASHLESS', ['status' => 'error']);
        $this->receipt($inTransit, 'sell', 900000, '2026-08-02T08:00:00+00:00', 'CASHLESS', ['status' => 'processing']);
        $this->receipt($inTransit, 'sell', 900000, '2026-08-02T08:00:00+00:00', 'CASHLESS', ['fiscal_code' => null], ['status' => 'ERROR']);
        $this->receipt($inTransit, 'sell', 900000, '2026-08-02T08:00:00+00:00', 'CASHLESS', [], ['is_test' => true]);
        $this->receipt($oldOrder, 'service_in', 100000, '2026-08-02T08:00:00+00:00');

        $response = $this->actingAs($owner)->getJson('/api/analytics/sales?date_from=2026-08-01&date_to=2026-08-31&scope=completed&payment_status=paid&status_id='.$done);
        $response->assertOk()
            ->assertJsonPath('fiscal.kpis.revenue.value', 700)
            ->assertJsonPath('fiscal.kpis.receipts.value', 2)
            ->assertJsonPath('fiscal.kpis.average_check.value', 500)
            ->assertJsonPath('fiscal.totals.sales', 1000)
            ->assertJsonPath('fiscal.totals.refunds', 300)
            ->assertJsonPath('fiscal.totals.refund_receipts', 1)
            ->assertJsonPath('fiscal.totals.cash', 200)
            ->assertJsonPath('fiscal.totals.cashless', 500)
            ->assertJsonPath('fiscal.trend.revenue.4', -300)
            ->assertJsonPath('fiscal.trend.revenue.2', 200)
            ->assertJsonPath('fiscal.trend.receipts.3', 1)
            ->assertJsonPath('fiscal.trend.average_check.3', 800)
            ->assertJsonPath('audit.total', 0);
        $this->assertEquals(700, array_sum($response->json('fiscal.trend.revenue')));
    }

    public function test_refund_is_counted_in_its_own_month_and_receipt_time_is_converted_to_kyiv(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
        [$source, $done] = $this->dictionaryRows();
        $order = $this->order($source, $owner->id, $done, 'delivered_paid', 'retail', 'paid', '2026-07-10 10:00:00');
        $this->receipt($order, 'sell', 100000, '2026-07-30T20:00:00+00:00');
        $receipt = $this->receipt($order, 'return', 35000, '2026-07-31T21:30:00+00:00');
        $this->assertSame('2026-08-01 00:30:00', $receipt->fresh()->fiscalized_at->format('Y-m-d H:i:s'));
        $receipt->update(['error_message' => null]);
        $this->assertSame('2026-08-01 00:30:00', $receipt->fresh()->fiscalized_at->format('Y-m-d H:i:s'));

        $this->actingAs($owner)->getJson('/api/analytics/sales?date_from=2026-08-01&date_to=2026-08-31')
            ->assertOk()->assertJsonPath('fiscal.totals.revenue', -350)
            ->assertJsonPath('fiscal.totals.receipts', 0)
            ->assertJsonPath('fiscal.totals.average_check', 0)
            ->assertJsonPath('fiscal.trend.revenue.0', -350)
            ->assertJsonPath('meta.comparison_from', '2026-07-01')
            ->assertJsonPath('meta.comparison_to', '2026-07-31')
            ->assertJsonPath('fiscal.kpis.revenue.previous', 1000);
    }

    public function test_initial_successful_checkbox_response_is_counted_before_fiscal_code_is_available(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
        [$source, $done] = $this->dictionaryRows();
        $order = $this->order($source, $owner->id, $done, 'delivered_paid', 'retail', 'paid', '2026-08-10 10:00:00');
        $this->receipt($order, 'sell', 39900, '2026-08-10T05:30:06.019847+00:00', 'CASHLESS', ['fiscal_code' => null], [
            'status' => 'CREATED', 'fiscal_code' => null, 'is_sent_dps' => false, 'is_test' => false,
        ]);
        $this->actingAs($owner)->getJson('/api/analytics/sales?date_from=2026-08-01&date_to=2026-08-31')
            ->assertOk()->assertJsonPath('fiscal.totals.revenue', 399)
            ->assertJsonPath('fiscal.totals.receipts', 1)
            ->assertJsonPath('fiscal.totals.cashless', 399);
    }

    public function test_receipt_totals_mixed_payments_change_and_unknown_payments_are_reconciled(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
        [$source, $done] = $this->dictionaryRows();
        $order = $this->order($source, $owner->id, $done, 'delivered_paid', 'retail', 'paid', '2026-08-10 10:00:00');
        $this->receipt($order, 'sell', 99999, '2026-08-10T10:00:00+00:00', 'CASH', [], [
            'total_sum' => 50001, 'total_rest' => 9999,
            'payments' => [['type' => 'CASH', 'value' => 30000], ['type' => 'CASHLESS', 'value' => 30000]],
        ]);
        $this->receipt($order, 'sell', 12550, '2026-08-10T10:00:00+00:00', 'CASH', [], ['payments' => []]);
        $this->actingAs($owner)->getJson('/api/analytics/sales?date_from=2026-08-10&date_to=2026-08-10')
            ->assertOk()->assertJsonPath('fiscal.totals.sales', 625.51)
            ->assertJsonPath('fiscal.totals.cash', 200.01)
            ->assertJsonPath('fiscal.totals.cashless', 300)
            ->assertJsonPath('fiscal.totals.other', 125.5)
            ->assertJsonPath('fiscal.totals.average_check', 312.76)
            ->assertJsonPath('fiscal.quality.unknown_payment_receipts', 1)
            ->assertJsonPath('meta.comparison_from', '2026-08-09')
            ->assertJsonPath('meta.comparison_to', '2026-08-09');
    }

    public function test_fiscal_business_filters_apply_and_foreign_currency_is_not_relabelled_as_uah(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
        $manager = User::factory()->create(['role' => User::ROLE_OPERATOR]);
        [$source, $done] = $this->dictionaryRows();
        $otherSource = DB::table('order_sources')->insertGetId(['code' => 'site', 'name' => 'Сайт', 'type' => 'order']);
        foreach ([[$source, $owner->id, 'wholesale', 'UAH', 10000], [$otherSource, $owner->id, 'wholesale', 'UAH', 20000], [$source, $manager->id, 'wholesale', 'UAH', 30000], [$source, $owner->id, 'retail', 'UAH', 40000], [$source, $owner->id, 'wholesale', 'PLN', 50000]] as [$sourceId, $managerId, $type, $currency, $amount]) {
            $order = $this->order($sourceId, $managerId, $done, 'delivered_paid', $type, 'paid', '2026-07-10 10:00:00');
            DB::table('orders')->where('id', $order)->update(['currency' => $currency]);
            $this->receipt($order, 'sell', $amount, '2026-08-10T10:00:00+00:00');
        }
        $this->actingAs($owner)->getJson('/api/analytics/sales?date_from=2026-08-01&date_to=2026-08-31&sale_type=wholesale&source_id='.$source.'&manager_id='.$owner->id)
            ->assertOk()->assertJsonPath('fiscal.totals.revenue', 100)->assertJsonPath('fiscal.totals.receipts', 1);
        $this->getJson('/api/analytics/sales?date_from=2026-08-01&date_to=2026-08-31&currency=PLN')
            ->assertOk()->assertJsonPath('fiscal.totals.revenue', 0);
    }

    public function test_legacy_dates_are_flagged_and_future_days_are_not_shown_as_zero_sales(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-08-15 12:00:00', 'Europe/Kyiv'));
        $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
        [$source, $done] = $this->dictionaryRows();
        $order = $this->order($source, $owner->id, $done, 'delivered_paid', 'retail', 'paid', '2026-08-10 10:00:00');
        $this->receipt($order, 'sell', 10000, '2026-08-10T10:00:00+00:00', 'CASH', ['created_at' => '2026-08-10 10:00:00'], ['fiscal_date' => 'invalid']);
        $this->actingAs($owner)->getJson('/api/analytics/sales?date_from=2026-08-01&date_to=2026-08-31')
            ->assertOk()->assertJsonPath('fiscal.quality.fallback_date_receipts', 1)
            ->assertJsonPath('fiscal.trend.revenue.9', 100)
            ->assertJsonPath('fiscal.trend.revenue.14', 0)
            ->assertJsonPath('fiscal.trend.revenue.15', null);
        $this->travelBack();
    }

    public function test_invalid_date_returns_validation_error_instead_of_server_error(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
        $this->actingAs($owner)->getJson('/api/analytics/sales?date_from=invalid&date_to=2026-08-31')
            ->assertUnprocessable()->assertJsonValidationErrors('date_from');
    }

    public function test_receipts_written_during_deployment_are_included_without_a_materialized_date(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
        [$source, $done] = $this->dictionaryRows();
        $order = $this->order($source, $owner->id, $done, 'delivered_paid', 'retail', 'paid', '2026-07-10 10:00:00');
        $current = $this->receipt($order, 'sell', 10000, '2026-07-31T21:30:00+00:00');
        $old = $this->receipt($order, 'sell', 20000, '2026-07-30T21:30:00+00:00');
        DB::table('fiscal_receipts')->whereIn('id', [$current->id, $old->id])->update(['fiscalized_at' => null]);
        $this->actingAs($owner)->getJson('/api/analytics/sales?date_from=2026-08-01&date_to=2026-08-31')
            ->assertOk()->assertJsonPath('fiscal.totals.revenue', 100)
            ->assertJsonPath('fiscal.kpis.revenue.previous', 200);
    }

    private function receipt(int $order, string $type, int $amount, string $date, string $payment = 'CASHLESS', array $attributes = [], array $meta = []): FiscalReceipt
    {
        $uuid = (string) \Illuminate\Support\Str::uuid();

        return FiscalReceipt::forceCreate(array_merge([
            'order_id' => $order, 'uuid' => $uuid, 'payload_hash' => $uuid,
            'fiscal_code' => $uuid, 'type' => $type, 'status' => 'success', 'total_amount' => $amount,
            'created_at' => '2026-07-01 12:00:00',
            'meta' => array_merge(['fiscal_date' => $date, 'status' => 'CREATED', 'total_sum' => $amount, 'payments' => [['type' => $payment, 'value' => $amount]]], $meta),
        ], $attributes));
    }

    private function dictionaryRows(): array
    {
        $now = now();
        $sourceId = DB::table('order_sources')->insertGetId([
            'code' => 'instagram', 'name' => 'Instagram', 'type' => 'order', 'sort_order' => 1,
            'is_default' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $statusIds = [];
        foreach ([
            ['delivered_paid', 'Завершено', 10],
            ['new', 'Новий', 20],
            ['cancelled', 'Скасовано', 30],
        ] as [$code, $name, $sort]) {
            $statusIds[$code] = DB::table('statuses')->insertGetId([
                'code' => $code, 'name' => $name, 'type' => 'order', 'sort_order' => $sort,
                'is_default' => $code === 'new', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return [$sourceId, $statusIds['delivered_paid'], $statusIds['new'], $statusIds['cancelled']];
    }

    private function order(int $sourceId, int $managerId, int $statusId, string $status, string $saleType, string $paymentStatus, string $createdAt): int
    {
        static $number = 1000;

        return DB::table('orders')->insertGetId([
            'order_number' => (string) ++$number,
            'source' => 'instagram',
            'source_id' => $sourceId,
            'status' => $status,
            'status_id' => $statusId,
            'payment_status' => $paymentStatus,
            'manager_id' => $managerId,
            'currency' => 'UAH',
            'sale_type' => $saleType,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function item(int $orderId, string $title, int $qty, float $price, ?float $cost): void
    {
        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'product_title' => $title,
            'price' => $price,
            'cost_price' => $cost,
            'qty' => $qty,
            'total' => $price * $qty,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
