<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Services\Inventory\SoleInventoryCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SoleInventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $category;

    private int $outdoor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-09-16 12:00:00', 'Europe/Kyiv'));
        $this->owner = User::factory()->create(['role' => User::ROLE_OWNER]);
        $this->category = DB::table('categories')->insertGetId(['name' => array_keys(SoleInventoryCatalog::CATEGORIES)[0]]);
        $this->outdoor = DB::table('categories')->insertGetId(['name' => array_keys(SoleInventoryCatalog::CATEGORIES)[1]]);
    }

    public function test_only_owner_can_read_or_change_inventory(): void
    {
        $this->getJson('/api/sole-inventory')->assertUnauthorized();
        foreach ([User::ROLE_OPERATOR, User::ROLE_PACKER] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/sole-inventory')->assertForbidden();
            $this->get('/sole-inventory')->assertForbidden();
            $this->postJson("/api/sole-inventory/{$this->category}/batches", $this->batch())->assertForbidden();
            $this->putJson("/api/sole-inventory/{$this->category}/batches/1", [])->assertForbidden();
            $this->putJson("/api/sole-inventory/{$this->category}/plan", [])->assertForbidden();
        }
        $this->actingAs($this->owner)->get('/sole-inventory')->assertOk()->assertSee('crm-sole-inventory');
    }

    public function test_read_does_not_invent_stock_or_create_plans_and_does_not_return_recent_order_table(): void
    {
        $response = $this->report()->assertOk()->assertJsonPath('categories.0.rows.0.remaining', null)
            ->assertJsonPath('categories.0.rows.0.status', 'unconfigured')
            ->assertJsonPath('categories.0.settings.lead_time_days', null)
            ->assertJsonPath('categories.0.settings.lookback_days', 0)
            ->assertJsonPath('totals.configured_sizes', 0)->assertJsonPath('totals.total_sizes', 7);
        $this->assertArrayNotHasKey('recent_shipments', $response->json('categories.0'));
        $this->assertArrayNotHasKey('opening_balances', $response->json('categories.0.settings'));
        $this->assertDatabaseCount('sole_inventory_plans', 0);
        $this->assertDatabaseCount('sole_inventory_batches', 0);
    }

    public function test_first_batch_starts_calculation_without_manually_entered_stock_or_setup(): void
    {
        $this->shipment($this->category, '36/37р - 24-24,5см', 30, 'shipped', '2026-09-03 10:00:00');
        $this->shipment($this->category, '36/37', 70, 'shipped', '2026-08-31 23:59:59');
        $this->receive();
        $this->report()->assertJsonPath('categories.0.counting_from', '2026-09-01')
            ->assertJsonPath('categories.0.rows.0.received_quantity', 100)
            ->assertJsonPath('categories.0.rows.0.consumed', 30)
            ->assertJsonPath('categories.0.rows.0.remaining', 70)
            ->assertJsonPath('categories.0.rows.0.daily_rate', 2)
            ->assertJsonPath('categories.0.rows.0.days_remaining', 35)
            ->assertJsonPath('categories.0.rows.0.status', 'missing_lead')
            ->assertJsonPath('categories.0.rows.0.reorder_date', null)
            ->assertJsonPath('categories.0.rate_days', 15)
            ->assertJsonPath('categories.0.months.0.total', 30);
        $this->assertDatabaseHas('sole_inventory_plans', ['basis' => 'receipts', 'opening_balances' => '[]']);
    }

    public function test_later_batch_adds_stock_without_resetting_consumption_or_daily_rate(): void
    {
        $this->receive();
        $this->shipment($this->category, '36/37', 30, 'shipped', '2026-09-05 10:00:00');
        $this->receive(['received_on' => '2026-09-10', 'quantities' => $this->quantities(50)]);
        $this->shipment($this->category, '36/37', 10, 'shipped', '2026-09-16 10:00:00');
        $this->report()->assertJsonPath('categories.0.counting_from', '2026-09-01')
            ->assertJsonPath('categories.0.rows.0.received_quantity', 150)
            ->assertJsonPath('categories.0.rows.0.consumed', 40)
            ->assertJsonPath('categories.0.rows.0.remaining', 110)
            ->assertJsonPath('categories.0.rows.0.daily_rate', 2)
            ->assertJsonPath('categories.0.months.0.total', 40);
    }

    public function test_historical_batch_and_corrected_arrival_date_recalculate_the_full_period(): void
    {
        $laterId = $this->receive(['received_on' => '2026-09-10']);
        $this->shipment($this->category, '36/37', 12, 'shipped', '2026-08-25 10:00:00');
        $this->shipment($this->category, '36/37', 18, 'shipped', '2026-09-05 10:00:00');
        $this->receive(['received_on' => '2026-08-01', 'quantities' => $this->quantities(50)]);
        $this->report()->assertJsonPath('categories.0.rows.0.remaining', 120)
            ->assertJsonPath('categories.0.months.0.total', 12)->assertJsonPath('categories.0.months.1.total', 18);
        $data = ['version' => 1, 'received_on' => '2026-07-01', 'quantities' => $this->quantities(80), 'note' => 'Уточнили кількість'];
        $this->putJson("/api/sole-inventory/{$this->category}/batches/{$laterId}", $data)->assertOk();
        $this->report()->assertJsonPath('categories.0.counting_from', '2026-07-01')
            ->assertJsonPath('categories.0.rows.0.received_quantity', 130)
            ->assertJsonPath('categories.0.rows.0.remaining', 100)
            ->assertJsonPath('categories.0.months.0.total', 0);
        $this->putJson("/api/sole-inventory/{$this->category}/batches/{$laterId}", $data)->assertConflict();
        $this->putJson("/api/sole-inventory/{$this->outdoor}/batches/{$laterId}", array_replace($data, ['quantities' => $this->quantities(80, $this->outdoor)]))->assertNotFound();
        $this->assertDatabaseCount('sole_inventory_plan_revisions', 3);
    }

    public function test_retried_batch_is_not_duplicated_and_payload_conflicts_are_rejected(): void
    {
        $data = $this->batch();
        $this->postBatch($data)->assertCreated();
        $this->postBatch($data)->assertCreated();
        $this->postBatch(array_replace($data, ['quantities' => $this->quantities(200)]))->assertConflict();
        $this->assertDatabaseCount('sole_inventory_batches', 1);
        $this->assertDatabaseCount('sole_inventory_plans', 1);
        $this->report()->assertJsonPath('categories.0.totals.received', 100);
    }

    public function test_consumption_uses_actual_quantities_and_first_dispatch_even_after_return_or_cancellation(): void
    {
        $this->receive();
        [, $delivery] = $this->shipment($this->category, '36-37-24 см', 5, 'returned', '2026-09-15 10:00:00');
        $this->history($delivery, 'in_transit', '2026-09-03 10:00:00');
        $this->history($delivery, 'at_warehouse', '2026-09-05 10:00:00');
        $this->history($delivery, 'refusal', '2026-09-15 10:00:00');
        foreach (['confirmed', 'packing', 'cancelled'] as $status) {
            $this->shipment($this->category, '36/37', 100, $status, '2026-09-10 10:00:00');
        }
        [, $cancelled] = $this->shipment($this->category, '36/37', 2, 'cancelled', '2026-09-11 10:00:00');
        $this->history($cancelled, 'in_transit', '2026-09-10 10:00:00');
        [, $old] = $this->shipment($this->category, '36/37', 8, 'returned', '2026-09-15 10:00:00');
        $this->history($old, 'in_transit', '2026-08-31 10:00:00');
        $this->shipment($this->category, '36/37', 90, 'shipped', null);
        $this->report()->assertJsonPath('categories.0.rows.0.consumed', 7)
            ->assertJsonPath('categories.0.rows.0.remaining', 93)
            ->assertJsonPath('categories.0.warnings.missing_date_orders', 1)
            ->assertJsonPath('categories.0.warnings.approximate_date_pairs', 0);
    }

    public function test_preserves_multiple_lines_but_deduplicates_orders_and_keeps_categories_separate(): void
    {
        $this->receive();
        $this->receive([], $this->outdoor);
        [$order] = $this->shipment($this->category, '36/37', 2, 'shipped', '2026-09-10 10:00:00', '590001');
        $product = DB::table('order_items')->where('order_id', $order)->value('product_id');
        DB::table('order_items')->insert(['order_id' => $order, 'product_id' => $product, 'size' => '36/37', 'qty' => 3]);
        $this->shipment($this->category, '36/37', 5, 'delivered_paid', '2026-09-15 10:00:00', ' 590001 ');
        $this->shipment($this->outdoor, '36/37', 4, 'shipped', '2026-09-10 10:00:00');
        $this->report()->assertJsonPath('categories.0.rows.0.consumed', 5)
            ->assertJsonPath('categories.0.warnings.duplicate_orders', 1)
            ->assertJsonPath('categories.1.rows.0.consumed', 4);
    }

    public function test_unknown_sizes_are_not_guessed_and_matching_variant_can_supply_missing_size(): void
    {
        $this->receive([], $this->outdoor);
        [$order] = $this->shipment($this->outdoor, null, 4, 'shipped', '2026-09-10 10:00:00');
        $product = DB::table('order_items')->where('order_id', $order)->value('product_id');
        $variant = DB::table('product_variants')->insertGetId(['product_id' => $product, 'size' => '42/43-27см']);
        DB::table('order_items')->where('order_id', $order)->update(['product_variant_id' => $variant]);
        $this->shipment($this->outdoor, '42/23', 6, 'shipped', '2026-09-10 10:00:00');
        $this->report()->assertJsonPath('categories.1.rows.3.consumed', 4)
            ->assertJsonPath('categories.1.warnings.unknown_size_pairs', 6)
            ->assertJsonPath('categories.1.issues.0.message', 'Невідомий розмір: 42/23');
    }

    public function test_audit_and_terminal_evidence_are_used_but_created_or_redirected_ttn_is_not_dispatch(): void
    {
        $this->receive();
        [$order] = $this->shipment($this->category, '36/37', 2, 'new', '2026-09-15 10:00:00', null);
        DB::table('order_status_changes')->insert(['order_id' => $order, 'order_number' => (string) $order,
            'new_status' => 'shipped', 'source' => 'manual_status', 'reason' => 'Відправлено', 'occurred_at' => '2026-09-03 09:00:00']);
        [, $returned] = $this->shipment($this->category, '36/37', 3, 'returned', '2026-09-15 10:00:00');
        $this->history($returned, 'refusal', '2026-09-14 10:00:00');
        [, $redirected] = $this->shipment($this->category, '36/37', 9, 'confirmed', '2026-09-10 10:00:00');
        $this->history($redirected, 'created', '2026-09-09 10:00:00');
        $this->history($redirected, 'in_transit', '2026-09-10 10:00:00', '104');
        $this->report()->assertJsonPath('categories.0.rows.0.consumed', 5)
            ->assertJsonPath('categories.0.warnings.without_ttn_orders', 1)
            ->assertJsonPath('categories.0.warnings.approximate_date_pairs', 3);
    }

    public function test_forecast_uses_full_days_and_delivery_time_and_exposes_time_until_next_order(): void
    {
        $this->receive();
        $this->configure(['lead_time_days' => 5, 'safety_days' => 2]);
        $this->shipment($this->category, '36/37', 60, 'shipped', '2026-09-10 10:00:00');
        $this->report()->assertJsonPath('categories.0.rows.0.remaining', 40)
            ->assertJsonPath('categories.0.rows.0.daily_rate', 4)
            ->assertJsonPath('categories.0.rows.0.days_remaining', 10)
            ->assertJsonPath('categories.0.rows.0.depletion_date', '2026-09-26')
            ->assertJsonPath('categories.0.rows.0.reorder_date', '2026-09-19')
            ->assertJsonPath('categories.0.rows.0.days_until_reorder', 3)
            ->assertJsonPath('categories.0.rows.0.reorder_point', 28)
            ->assertJsonPath('categories.0.next_order.size', '36/37')
            ->assertJsonPath('categories.0.rows.1.status', 'not_received');
        $this->shipment($this->category, '36/37', 50, 'shipped', '2026-09-16 10:00:00');
        $this->report()->assertJsonPath('categories.0.rows.0.remaining', -10)
            ->assertJsonPath('categories.0.rows.0.daily_rate', 4)
            ->assertJsonPath('categories.0.rows.0.status', 'depleted');
    }

    public function test_recent_window_affects_forecast_only_not_stock_or_monthly_consumption(): void
    {
        $this->receive(['received_on' => '2026-07-01', 'quantities' => $this->quantities(1000)]);
        $this->shipment($this->category, '36/37', 100, 'shipped', '2026-07-05 10:00:00');
        $this->shipment($this->category, '36/37', 60, 'shipped', '2026-09-05 10:00:00');
        $this->configure(['lookback_days' => 30]);
        $this->report()->assertJsonPath('categories.0.rate_days', 30)
            ->assertJsonPath('categories.0.rows.0.daily_rate', 2)
            ->assertJsonPath('categories.0.rows.0.remaining', 840)
            ->assertJsonPath('categories.0.months.0.total', 100)
            ->assertJsonPath('categories.0.months.1.total', 0)
            ->assertJsonPath('categories.0.months.2.total', 60);
    }

    public function test_arrival_today_has_stock_but_no_forecast_until_a_full_day_has_passed(): void
    {
        $this->receive(['received_on' => '2026-09-16']);
        $this->shipment($this->category, '36/37', 3, 'shipped', '2026-09-16 10:00:00');
        $this->shipment($this->category, '36/37', 50, 'shipped', '2026-09-16 15:00:00');
        $this->report()->assertJsonPath('categories.0.rate_days', 0)
            ->assertJsonPath('categories.0.rows.0.remaining', 97)
            ->assertJsonPath('categories.0.rows.0.days_remaining', null)
            ->assertJsonPath('categories.0.rows.0.reorder_date', null);
    }

    public function test_validation_prevents_future_empty_invalid_batches_and_stale_settings(): void
    {
        $this->postBatch($this->batch(['received_on' => '2026-09-17']))->assertUnprocessable();
        $this->postBatch($this->batch(['quantities' => $this->quantities(0)]))->assertUnprocessable();
        $this->postBatch($this->batch(['quantities' => $this->quantities(-1)]))->assertUnprocessable();
        $this->postBatch($this->batch(['quantities' => [['size' => '42/23', 'quantity' => 4]]]))->assertUnprocessable();
        $this->postBatch($this->batch(['quantities' => [['size' => '36/37', 'quantity' => 2], ['size' => '36/37', 'quantity' => 3], ['size' => '40/41', 'quantity' => 4]]]))->assertUnprocessable();
        $other = DB::table('categories')->insertGetId(['name' => 'Інша категорія']);
        $this->postJson("/api/sole-inventory/{$other}/batches", $this->batch())->assertNotFound();
        $this->receive();
        $this->configure();
        $this->putJson("/api/sole-inventory/{$this->category}/plan", ['version' => 1, 'lead_time_days' => 5, 'safety_days' => 0, 'lookback_days' => 0])->assertConflict();
        $this->putJson("/api/sole-inventory/{$this->category}/plan", ['version' => 2, 'lead_time_days' => 5, 'safety_days' => 0, 'lookback_days' => 0, 'opening_balances' => [100]])->assertUnprocessable();
    }

    public function test_legacy_balances_are_preserved_and_not_relabelled_as_purchases(): void
    {
        $plan = DB::table('sole_inventory_plans')->insertGetId(['category_id' => $this->category, 'opening_date' => '2026-09-01', 'opening_balances' => json_encode($this->quantities(100)), 'version' => 1]);
        DB::table('sole_inventory_movements')->insert(['plan_id' => $plan, 'size' => '36/37', 'kind' => 'adjustment', 'quantity' => -5, 'movement_date' => '2026-09-10', 'request_key' => (string) Str::uuid(), 'created_at' => now()]);
        $this->receive(['received_on' => '2026-09-12', 'quantities' => $this->quantities(20)]);
        $this->shipment($this->category, '36/37', 10, 'shipped', '2026-09-10 10:00:00');
        $this->configure();
        $this->report()->assertJsonPath('categories.0.legacy_basis', true)
            ->assertJsonPath('categories.0.rows.0.legacy_quantity', 100)
            ->assertJsonPath('categories.0.rows.0.received_quantity', 20)
            ->assertJsonPath('categories.0.rows.0.remaining', 105);
        $this->postBatch($this->batch(['received_on' => '2026-08-01']))->assertUnprocessable();
    }

    private function quantities(int $qty = 100, ?int $category = null): array
    {
        $sizes = $category === $this->outdoor ? ['36/37', '38/39', '40/41', '42/43'] : ['36/37', '38/39', '40/41'];

        return array_map(fn ($size) => ['size' => $size, 'quantity' => $size === '36/37' ? $qty : 0], $sizes);
    }

    private function batch(array $overrides = [], ?int $category = null): array
    {
        return array_replace(['request_key' => (string) Str::uuid(), 'received_on' => '2026-09-01', 'quantities' => $this->quantities(100, $category), 'note' => null], $overrides);
    }

    private function postBatch(array $data, ?int $category = null)
    {
        $category ??= $this->category;

        return $this->actingAs($this->owner)->postJson("/api/sole-inventory/{$category}/batches", $data);
    }

    private function receive(array $overrides = [], ?int $category = null): int
    {
        return $this->postBatch($this->batch($overrides, $category), $category)->assertCreated()->json('id');
    }

    private function configure(array $overrides = []): void
    {
        $version = DB::table('sole_inventory_plans')->where('category_id', $this->category)->value('version') ?? 0;
        $this->actingAs($this->owner)->putJson("/api/sole-inventory/{$this->category}/plan", array_replace(['version' => $version, 'lead_time_days' => null, 'safety_days' => 0, 'lookback_days' => 0], $overrides))->assertOk();
    }

    private function report()
    {
        return $this->actingAs($this->owner)->getJson('/api/sole-inventory');
    }

    private function shipment(int $category, ?string $size, int $qty, string $status, ?string $date, ?string $ttn = 'auto'): array
    {
        static $number = 910000;
        $product = DB::table('products')->insertGetId(['title' => 'Капці', 'category_id' => $category]);
        $order = DB::table('orders')->insertGetId(['order_number' => (string) ++$number, 'status' => $status, 'status_changed_at' => $date,
            'currency' => 'UAH', 'sale_type' => 'retail', 'payment_status' => 'unpaid', 'created_at' => '2026-01-01 10:00:00', 'updated_at' => $date]);
        DB::table('order_items')->insert(['order_id' => $order, 'product_id' => $product, 'size' => $size, 'qty' => $qty]);
        $delivery = DB::table('order_deliveries')->insertGetId(['order_id' => $order, 'carrier' => 'nova_poshta', 'delivery_type' => 'warehouse', 'ttn' => $ttn === 'auto' ? '590000'.$order : $ttn]);

        return [$order, $delivery];
    }

    private function history(int $delivery, string $status, string $date, ?string $source = null): void
    {
        DB::table('order_delivery_status_histories')->insert(['order_delivery_id' => $delivery, 'status_code' => $status, 'entered_at' => $date, 'source_code' => $source]);
    }
}
