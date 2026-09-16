<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Services\Costs\LaminateCostCalculator;
use App\Services\Costs\ProductionCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class LaminateCostsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/production-costs/laminate-calculations';

    private function payload(array $replace = []): array
    {
        // Лише вигадані розцінки; приватні рахунки не зберігаємо у відкритих тестах.
        return array_replace(['request_key' => (string) Str::uuid(), 'name' => 'Тестове полотно', 'purchased_on' => null, 'note' => null,
            'plush_price_metre_uah' => '120', 'plush_width_cm' => '200', 'plush_shipping_metre_uah' => null,
            'web_roll_price_uah' => '800', 'web_roll_length_m' => '40', 'web_width_cm' => '100', 'web_shipping_roll_uah' => null,
            'foam_sheet_price_usd' => '4', 'usd_rate' => '40', 'foam_sheet_length_cm' => '200', 'foam_sheet_width_cm' => '100', 'foam_shipping_sheet_uah' => null,
            'insole_length_cm' => '25', 'insole_width_cm' => '10', 'upper_top_cm' => '20', 'upper_bottom_cm' => '10', 'upper_height_cm' => '10'], $replace);
    }

    private function owner(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    }

    public function test_owner_only_and_reads_do_not_create_records(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->postJson(self::URL, $this->payload())->assertUnauthorized();
        $this->putJson(self::URL.'/1', [])->assertUnauthorized();
        foreach ([User::ROLE_OPERATOR, User::ROLE_PACKER] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->getJson(self::URL)->assertForbidden();
            $this->postJson(self::URL, $this->payload())->assertForbidden();
            $this->putJson(self::URL.'/1', [])->assertForbidden();
        }
        $this->owner();
        $this->getJson(self::URL)->assertOk()->assertJsonPath('total', 0);
        $this->getJson(self::URL.'?page=0')->assertUnprocessable();
        $this->assertDatabaseCount('production_cost_batches', 0);
        $this->assertDatabaseCount('production_cost_batch_revisions', 0);
    }

    public function test_three_layers_cover_two_rectangles_and_two_trapezoids_only(): void
    {
        $this->owner();
        $this->postJson(self::URL, $this->payload())->assertCreated()->assertJsonPath('quantity', 1)->assertJsonPath('purchased_on', null)
            ->assertJsonPath('inputs.plush_price_metre_uah', '120.00')->assertJsonPath('inputs.web_roll_length_m', '40.0000')
            ->assertJsonPath('calculation.total_uah', 160)->assertJsonPath('calculation.square_metre_cost_uah', 160)
            ->assertJsonPath('calculation.unit_cost_uah', 12.8)->assertJsonPath('calculation.foam_sheet_uah', 160)
            ->assertJsonPath('calculation.insole_pair_area_m2', 0.05)->assertJsonPath('calculation.upper_pair_area_m2', 0.03)
            ->assertJsonPath('calculation.pair_area_m2', 0.08)->assertJsonPath('calculation.insole_pair_cost_uah', 8)
            ->assertJsonPath('calculation.upper_pair_cost_uah', 4.8)->assertJsonCount(3, 'calculation.breakdown')
            ->assertJsonPath('calculation.breakdown.0.square_metre_cost_uah', 60)->assertJsonPath('calculation.breakdown.1.square_metre_cost_uah', 20)
            ->assertJsonPath('calculation.breakdown.2.square_metre_cost_uah', 80)->assertJsonPath('calculation.breakdown.2.unit_cost_uah', 6.4);
        $this->assertDatabaseCount('production_cost_batches', 1);
        $this->assertDatabaseCount('production_cost_batch_revisions', 1);
        $this->assertDatabaseCount('sole_inventory_batches', 0);
    }

    public function test_deliveries_use_their_own_purchase_units_and_distinguish_unknown_from_zero(): void
    {
        $this->owner();
        $unknown = $this->payload();
        unset($unknown['foam_shipping_sheet_uah']);
        $this->postJson(self::URL, $unknown)->assertCreated()->assertJsonPath('inputs.foam_shipping_sheet_uah', null)->assertJsonPath('calculation.breakdown.2.shipping_included', false);
        $this->postJson(self::URL, $this->payload(['plush_shipping_metre_uah' => '20,00', 'web_shipping_roll_uah' => '400', 'foam_shipping_sheet_uah' => '20']))->assertCreated()
            ->assertJsonPath('calculation.square_metre_cost_uah', 190)->assertJsonPath('calculation.unit_cost_uah', 15.2)
            ->assertJsonPath('calculation.breakdown.0.shipping_included', true)->assertJsonPath('calculation.breakdown.1.shipping_included', true)->assertJsonPath('calculation.breakdown.2.shipping_included', true);
        $this->postJson(self::URL, $this->payload(['plush_shipping_metre_uah' => '0', 'web_shipping_roll_uah' => '']))->assertCreated()
            ->assertJsonPath('calculation.breakdown.0.shipping_included', true)->assertJsonPath('calculation.breakdown.1.shipping_included', false);
    }

    public function test_currency_rounding_fractional_dimensions_and_unrounded_area_price(): void
    {
        $this->owner();
        $this->postJson(self::URL, $this->payload(['foam_sheet_price_usd' => '0,05', 'usd_rate' => '7,1234']))->assertCreated()
            ->assertJsonPath('calculation.foam_sheet_uah', 0.36)->assertJsonPath('calculation.unit_cost_uah', 6.4144);
        $response = $this->postJson(self::URL, $this->payload(['plush_width_cm' => '150', 'web_roll_length_m' => '37,1250', 'insole_width_cm' => '10,25']))->assertCreated()
            ->assertJsonPath('inputs.web_roll_length_m', '37.1250')->assertJsonPath('inputs.insole_width_cm', '10.25');
        $expected = (120 / 1.5 + 800 / 37.125 + 160 / 2) * (2 * 25 * 10.25 / 10000 + 0.03);
        $this->assertEqualsWithDelta(round($expected, 6), $response->json('calculation.unit_cost_uah'), 0.0000001);
    }

    public function test_invalid_fields_geometry_and_client_totals_are_rejected(): void
    {
        $this->owner();
        foreach ([['plush_price_metre_uah', ''], ['plush_price_metre_uah', -1], ['plush_price_metre_uah', '1.001'], ['plush_price_metre_uah', 1000001],
            ['plush_width_cm', 0], ['web_roll_length_m', 0], ['web_roll_length_m', '1e2'], ['web_roll_length_m', '1.00001'],
            ['web_width_cm', 1001], ['foam_sheet_price_usd', 1001], ['usd_rate', 0], ['usd_rate', '1.00001'], ['foam_sheet_length_cm', 0],
            ['insole_width_cm', -1], ['upper_top_cm', 0], ['upper_height_cm', 0], ['plush_shipping_metre_uah', '-1'],
            ['name', ''], ['purchased_on', '2026-02-30'], ['quantity', 1], ['unit_cost_uah', 12], ['total_uah', 12], ['component', 'foam']] as [$field, $value]) {
            $this->postJson(self::URL, $this->payload([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        foreach ([['plush_width_cm' => 5], ['web_roll_length_m' => '0.01'], ['foam_sheet_width_cm' => 5], ['upper_height_cm' => 300, 'upper_top_cm' => 300, 'upper_bottom_cm' => 250]] as $replace) {
            $this->postJson(self::URL, $this->payload($replace))->assertUnprocessable()->assertJsonValidationErrors('insole_length_cm');
        }
        $small = array_fill_keys(array_filter(array_keys(LaminateCostCalculator::FIELDS), fn ($field) => str_ends_with($field, '_cm')), '0.01');
        $this->postJson(self::URL, $this->payload($small + ['web_roll_length_m' => '0.0001', 'foam_sheet_price_usd' => '1000', 'usd_rate' => '1000']))->assertUnprocessable()->assertJsonValidationErrors('plush_price_metre_uah');
        $this->assertDatabaseCount('production_cost_batches', 0);
    }

    public function test_idempotency_versions_component_isolation_and_independent_prices(): void
    {
        $this->owner();
        $payload = $this->payload();
        $id = $this->postJson(self::URL, $payload)->assertCreated()->json('id');
        $this->postJson(self::URL, array_replace($payload, ['usd_rate' => '40.0000']))->assertCreated()->assertJsonPath('id', $id);
        $this->postJson(self::URL, array_replace($payload, ['usd_rate' => '41']))->assertConflict();
        $edit = array_replace($payload, ['version' => 1, 'foam_sheet_price_usd' => '5']);
        unset($edit['request_key']);
        $this->putJson(self::URL.'/'.$id, $edit)->assertOk()->assertJsonPath('version', 2)->assertJsonPath('calculation.unit_cost_uah', 14.4);
        $this->putJson(self::URL.'/'.$id, $edit)->assertConflict();
        $this->putJson(self::URL.'/999999', $edit)->assertNotFound();
        $otherKey = (string) Str::uuid();
        $foam = app(ProductionCostService::class)->save(['request_key' => $otherKey, 'name' => 'Окрема вставка', 'sheet_price_usd' => 4, 'usd_rate' => 40, 'sheet_length_cm' => 200, 'sheet_width_cm' => 100, 'blank_length_cm' => 20, 'blank_width_cm' => 10], null, null, 'foam');
        $this->putJson(self::URL.'/'.$foam['id'], $edit)->assertNotFound();
        $this->postJson(self::URL, $this->payload(['request_key' => $otherKey]))->assertConflict();
        $this->assertDatabaseHas('production_cost_batches', ['id' => $foam['id'], 'component' => 'foam', 'version' => 1, 'total_uah' => 160]);
        $this->assertDatabaseCount('production_cost_batch_revisions', 3);
        $this->assertDatabaseCount('sole_inventory_batches', 0);
    }

    public function test_private_import_preserves_later_edits_and_validates_geometry(): void
    {
        Storage::fake('local');
        $payload = $this->payload();
        Storage::disk('local')->put('laminate.json', json_encode($payload));
        $path = Storage::disk('local')->path('laminate.json');
        $this->artisan('production-costs:import-laminate', ['--file' => $path])->assertSuccessful();
        $this->artisan('production-costs:import-laminate', ['--file' => $path])->assertSuccessful();
        $id = DB::table('production_cost_batches')->value('id');
        $this->owner();
        $edit = array_replace($payload, ['version' => 1, 'insole_length_cm' => '50']);
        unset($edit['request_key']);
        $this->putJson(self::URL.'/'.$id, $edit)->assertOk()->assertJsonPath('calculation.unit_cost_uah', 20.8);
        $this->artisan('production-costs:import-laminate', ['--file' => $path])->assertSuccessful();
        $this->assertDatabaseHas('production_cost_batches', ['id' => $id, 'version' => 2, 'unit_cost_uah' => 20.8, 'user_id' => null]);
        Storage::disk('local')->put('laminate.json', json_encode($this->payload(['web_width_cm' => 1])));
        $this->artisan('production-costs:import-laminate', ['--file' => $path])->assertFailed();
        $this->assertDatabaseCount('production_cost_batches', 1);
        $this->assertDatabaseCount('production_cost_batch_revisions', 2);
    }

    public function test_history_pagination_only_lists_laminate(): void
    {
        $this->owner();
        for ($i = 1; $i <= 21; $i++) {
            $this->postJson(self::URL, $this->payload(['name' => 'Полотно '.$i]))->assertCreated();
        }
        $this->getJson(self::URL)->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('data.0.name', 'Полотно 21');
        $this->getJson(self::URL.'?page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 21);
    }
}
