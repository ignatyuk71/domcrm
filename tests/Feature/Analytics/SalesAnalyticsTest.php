<?php

namespace Tests\Feature\Analytics;

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
