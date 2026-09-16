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
            $this->putJson("/api/sole-inventory/{$this->category}/plan", $this->plan())->assertForbidden();
            $this->postJson("/api/sole-inventory/{$this->category}/movements", $this->movement())->assertForbidden();
        }
        $this->actingAs($this->owner)->get('/sole-inventory')->assertOk()->assertSee('crm-sole-inventory');
    }

    public function test_unconfigured_inventory_does_not_invent_balances_or_write_on_read(): void
    {
        $this->shipment($this->category, '36/37р - 24-24,5см', 3, 'shipped', '2026-09-15 10:00:00');
        $this->report()->assertOk()->assertJsonPath('categories.0.rows.0.remaining', null)
            ->assertJsonPath('categories.0.rows.0.shipped_in_window', 3)
            ->assertJsonPath('categories.0.rows.0.status', 'unconfigured')
            ->assertJsonPath('categories.0.settings.lead_time_days', null)
            ->assertJsonPath('totals.configured_sizes', 0)->assertJsonPath('totals.total_sizes', 7);
        $this->assertDatabaseCount('sole_inventory_plans', 0);
        $this->assertDatabaseCount('sole_inventory_movements', 0);
    }

    public function test_consumes_actual_quantities_once_and_returns_do_not_restore_raw_soles(): void
    {
        $this->savePlan();
        [$order, $delivery] = $this->shipment($this->category, '36-37-24 см', 5, 'returned', '2026-09-15 10:00:00');
        $this->history($delivery, 'in_transit', '2026-09-03 10:00:00');
        $this->history($delivery, 'at_warehouse', '2026-09-05 10:00:00');
        $this->history($delivery, 'refusal', '2026-09-15 10:00:00');
        $this->shipment($this->category, '36/37', 100, 'confirmed', '2026-09-10 10:00:00');
        $this->shipment($this->category, '36/37', 100, 'packing', '2026-09-10 10:00:00');
        $this->shipment($this->category, '36/37', 100, 'cancelled', '2026-09-10 10:00:00');
        // Скасування після реального відправлення не повертає сировину.
        [, $cancelledDelivery] = $this->shipment($this->category, '36/37', 2, 'cancelled', '2026-09-11 10:00:00');
        $this->history($cancelledDelivery, 'in_transit', '2026-09-10 10:00:00');
        $this->report()->assertJsonPath('categories.0.rows.0.consumed', 7)
            ->assertJsonPath('categories.0.rows.0.remaining', 93)
            ->assertJsonPath('categories.0.rows.0.shipped_in_window', 7)
            ->assertJsonPath('categories.0.warnings.approximate_date_pairs', 0);
    }

    public function test_uses_dispatch_day_not_order_creation_or_later_return_and_excludes_today_from_rate(): void
    {
        $this->savePlan();
        [, $old] = $this->shipment($this->category, '36/37', 8, 'returned', '2026-09-15 09:00:00');
        $this->history($old, 'in_transit', '2026-08-31 10:00:00');
        $this->shipment($this->category, '36/37', 3, 'shipped', '2026-09-01 00:00:00');
        $this->shipment($this->category, '36/37', 2, 'shipped', '2026-09-16 10:00:00');
        $this->shipment($this->category, '36/37', 50, 'shipped', '2026-09-16 15:00:00');
        $this->shipment($this->category, '36/37', 90, 'shipped', null);
        $this->report()->assertJsonPath('categories.0.rows.0.consumed', 5)
            ->assertJsonPath('categories.0.rows.0.shipped_in_window', 11)
            ->assertJsonPath('categories.0.rows.0.remaining', 95)
            ->assertJsonPath('categories.0.warnings.missing_date_orders', 1)
            ->assertJsonPath('categories.0.trend.29.date', '2026-09-15');
    }

    public function test_preserves_multiple_lines_but_deduplicates_orders_with_same_ttn(): void
    {
        $this->savePlan();
        [$order] = $this->shipment($this->category, '36/37', 2, 'shipped', '2026-09-10 10:00:00', '590001');
        $product = DB::table('order_items')->where('order_id', $order)->value('product_id');
        DB::table('order_items')->insert(['order_id' => $order, 'product_id' => $product, 'size' => '36/37', 'qty' => 3]);
        $this->shipment($this->category, '36/37', 5, 'delivered_paid', '2026-09-15 10:00:00', ' 590001 ');
        $this->shipment($this->outdoor, '36/37', 4, 'shipped', '2026-09-10 10:00:00');
        $this->report()->assertJsonPath('categories.0.rows.0.consumed', 5)
            ->assertJsonPath('categories.0.warnings.duplicate_orders', 1)
            ->assertJsonPath('categories.1.rows.0.shipped_in_window', 4);
    }

    public function test_unrecognized_sizes_are_reported_without_guessing_and_missing_size_uses_matching_variant(): void
    {
        [$order] = $this->shipment($this->outdoor, null, 4, 'shipped', '2026-09-10 10:00:00');
        $product = DB::table('order_items')->where('order_id', $order)->value('product_id');
        $variant = DB::table('product_variants')->insertGetId(['product_id' => $product, 'size' => '42/43-27см']);
        DB::table('order_items')->where('order_id', $order)->update(['product_variant_id' => $variant]);
        $this->shipment($this->outdoor, '42/23', 6, 'shipped', '2026-09-10 10:00:00');
        $this->report()->assertJsonPath('categories.1.rows.3.shipped_in_window', 4)
            ->assertJsonPath('categories.1.warnings.unknown_size_pairs', 6)
            ->assertJsonPath('categories.1.issues.0.message', 'Невідомий розмір: 42/23');
    }

    public function test_crm_audit_is_used_after_status_changes_and_terminal_only_dates_are_marked_approximate(): void
    {
        [$order] = $this->shipment($this->category, '36/37', 2, 'new', '2026-09-15 10:00:00', null);
        DB::table('order_status_changes')->insert(['order_id' => $order, 'order_number' => (string) $order,
            'new_status' => 'shipped', 'source' => 'manual_status', 'reason' => 'Відправлено', 'occurred_at' => '2026-09-03 09:00:00']);
        [, $returned] = $this->shipment($this->category, '36/37', 3, 'returned', '2026-09-15 10:00:00');
        $this->history($returned, 'refusal', '2026-09-14 10:00:00');
        $this->report()->assertJsonPath('categories.0.rows.0.shipped_in_window', 5)
            ->assertJsonPath('categories.0.warnings.without_ttn_orders', 1)
            ->assertJsonPath('categories.0.warnings.approximate_date_pairs', 3);
    }

    public function test_nova_poshta_address_change_and_created_waybill_alone_are_not_dispatch(): void
    {
        [, $delivery] = $this->shipment($this->category, '36/37', 9, 'confirmed', '2026-09-10 10:00:00');
        $this->history($delivery, 'created', '2026-09-09 10:00:00');
        $this->history($delivery, 'in_transit', '2026-09-10 10:00:00', '104');
        $this->report()->assertJsonPath('categories.0.rows.0.shipped_in_window', 0);
    }

    public function test_forecast_uses_calendar_days_lead_time_and_buffer_and_keeps_negative_balance_visible(): void
    {
        $this->savePlan(['opening_balances' => $this->balances(100), 'lead_time_days' => 30, 'safety_days' => 10]);
        $this->shipment($this->category, '36/37', 60, 'shipped', '2026-09-10 10:00:00');
        $this->report()->assertJsonPath('categories.0.rows.0.remaining', 40)
            ->assertJsonPath('categories.0.rows.0.daily_rate', 2)
            ->assertJsonPath('categories.0.rows.0.days_remaining', 20)
            ->assertJsonPath('categories.0.rows.0.depletion_date', '2026-10-06')
            ->assertJsonPath('categories.0.rows.0.reorder_date', '2026-08-27')
            ->assertJsonPath('categories.0.rows.0.reorder_point', 80)
            ->assertJsonPath('categories.0.rows.0.status', 'order_now');
        $this->shipment($this->category, '36/37', 50, 'shipped', '2026-09-16 10:00:00');
        $this->report()->assertJsonPath('categories.0.rows.0.remaining', -10)
            ->assertJsonPath('categories.0.rows.0.daily_rate', 2)
            ->assertJsonPath('categories.0.rows.0.status', 'depleted');
    }

    public function test_zero_rate_and_missing_lead_time_do_not_produce_fictitious_dates(): void
    {
        $this->savePlan(['lead_time_days' => null]);
        $this->report()->assertJsonPath('categories.0.rows.0.days_remaining', null)
            ->assertJsonPath('categories.0.rows.0.reorder_date', null)
            ->assertJsonPath('categories.0.rows.0.status', 'no_history');
        $this->shipment($this->category, '36/37', 30, 'shipped', '2026-09-10 10:00:00');
        $this->report()->assertJsonPath('categories.0.rows.0.status', 'missing_lead')
            ->assertJsonPath('categories.0.rows.0.reorder_date', null);
    }

    public function test_replenishments_and_signed_adjustments_are_audited_and_retry_safe(): void
    {
        $this->savePlan();
        $movement = $this->movement();
        $this->postMovement($movement)->assertCreated();
        $this->postMovement($movement)->assertCreated();
        $this->postMovement(array_replace($movement, ['quantity' => 10]))->assertConflict();
        $this->postMovement($this->movement(['kind' => 'adjustment', 'quantity' => -5, 'note' => 'Брак']))->assertCreated();
        $this->assertDatabaseCount('sole_inventory_movements', 2);
        $this->assertDatabaseCount('sole_inventory_plan_revisions', 1);
        $this->report()->assertJsonPath('categories.0.rows.0.remaining', 115)
            ->assertJsonPath('categories.0.rows.0.movements_quantity', 15);
        $this->savePlan(['version' => 1, 'opening_date' => '2026-09-02'], 422);
        $this->savePlan(['version' => 1, 'safety_days' => 20]);
        $this->assertDatabaseCount('sole_inventory_plan_revisions', 2);
    }

    public function test_rejects_stale_versions_future_dates_invalid_sizes_and_unsafe_movements(): void
    {
        $this->postMovement($this->movement())->assertUnprocessable();
        $this->savePlan();
        $this->savePlan([], 409);
        $this->savePlan(['version' => 1, 'opening_date' => '2026-09-17'], 422);
        $this->savePlan(['version' => 1, 'opening_balances' => [['size' => '42/23', 'quantity' => 1]]], 422);
        $this->postMovement($this->movement(['movement_date' => '2026-08-31']))->assertUnprocessable();
        $this->postMovement($this->movement(['movement_date' => '2026-09-17']))->assertUnprocessable();
        $this->postMovement($this->movement(['quantity' => -1]))->assertUnprocessable();
        $this->postMovement($this->movement(['quantity' => 0]))->assertUnprocessable();
        $this->postMovement($this->movement(['size' => '38/39']))->assertUnprocessable();
        $this->postMovement($this->movement(['kind' => 'adjustment', 'quantity' => -1, 'note' => null]))->assertUnprocessable();
        $other = DB::table('categories')->insertGetId(['name' => 'Інша категорія']);
        $this->putJson("/api/sole-inventory/{$other}/plan", $this->plan())->assertNotFound();
    }

    private function balances(int $qty = 100): array
    {
        return [['size' => '36/37', 'quantity' => $qty], ['size' => '38/39', 'quantity' => null], ['size' => '40/41', 'quantity' => null]];
    }

    private function plan(array $overrides = []): array
    {
        return array_replace(['version' => 0, 'opening_date' => '2026-09-01', 'opening_balances' => $this->balances(), 'lead_time_days' => 60, 'safety_days' => 14, 'lookback_days' => 30], $overrides);
    }

    private function savePlan(array $overrides = [], int $status = 200): void
    {
        $this->actingAs($this->owner)->putJson("/api/sole-inventory/{$this->category}/plan", $this->plan($overrides))->assertStatus($status);
    }

    private function movement(array $overrides = []): array
    {
        return array_replace(['request_key' => (string) Str::uuid(), 'size' => '36/37', 'kind' => 'receipt', 'quantity' => 20, 'movement_date' => '2026-09-16', 'note' => null], $overrides);
    }

    private function postMovement(array $data)
    {
        return $this->actingAs($this->owner)->postJson("/api/sole-inventory/{$this->category}/movements", $data);
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
