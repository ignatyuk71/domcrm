<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Services\Costs\ProductionCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FurCostsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/production-costs/fur-batches';

    private function payload(array $replace = []): array
    {
        // Вигадані суми: приватні закупівлі не потрапляють у відкриті тести.
        return array_replace(['request_key' => (string) Str::uuid(), 'name' => 'Тестове хутро', 'purchased_on' => null, 'note' => null,
            'fabric_length' => '10', 'length_unit' => 'metre', 'fabric_width_cm' => '200',
            'top_width_cm' => '20', 'bottom_width_cm' => '10', 'height_cm' => '10',
            'goods_cny' => '100', 'china_shipping_cny' => '10', 'commission_percent' => '10', 'international_shipping_usd' => '10',
            'ukraine_shipping_uah' => null, 'other_costs_uah' => '0', 'cny_rate' => '6', 'usd_rate' => '40'], $replace);
    }

    private function owner(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    }

    private function localPayload(array $replace = []): array
    {
        $payload = array_diff_key($this->payload(), array_flip(['goods_cny', 'china_shipping_cny', 'commission_percent', 'international_shipping_usd', 'cny_rate', 'usd_rate']));

        return array_replace($payload, ['purchase_source' => 'ukraine', 'goods_uah' => '3000'], $replace);
    }

    public function test_row_cutting_reference_and_editable_dimensions_recalculate_and_persist(): void
    {
        $this->owner();
        // Контрольний приклад розкрою, не рахунок постачальника.
        $payload = $this->localPayload(['fabric_length' => '1', 'fabric_width_cm' => '180', 'top_width_cm' => '20', 'bottom_width_cm' => '13', 'height_cm' => '8', 'goods_uah' => '1200']);
        $id = $this->postJson(self::URL, $payload)->assertCreated()
            ->assertJsonPath('inputs.cut_length_cm', '100.00')
            ->assertJsonPath('calculation.layout.fabric_length_cm', 100)
            ->assertJsonPath('calculation.layout.pieces_per_row', 5)->assertJsonPath('calculation.layout.rows_per_cut', 22)
            ->assertJsonPath('calculation.layout.total_pieces', 110)->assertJsonPath('calculation.layout.pairs', 55)
            ->assertJsonPath('calculation.layout.offcut_area_m2', 0.348)
            ->assertJsonPath('calculation.piece_cost_uah', 10.909091)->assertJsonPath('calculation.unit_cost_uah', 21.818182)->json('id');
        $edit = array_replace($payload, ['version' => 1, 'height_cm' => '9']);
        unset($edit['request_key']);
        $this->putJson(self::URL.'/'.$id, $edit)->assertOk()->assertJsonPath('calculation.layout.total_pieces', 100)->assertJsonPath('calculation.unit_cost_uah', 24);
        $edit = array_replace($edit, ['version' => 2, 'cut_length_cm' => '50,00']);
        $this->putJson(self::URL.'/'.$id, $edit)->assertOk()->assertJsonPath('inputs.cut_length_cm', '50.00')
            ->assertJsonPath('calculation.layout.pieces_per_row', 2)->assertJsonPath('calculation.layout.total_pieces', 80)->assertJsonPath('calculation.unit_cost_uah', 30);
        $this->assertDatabaseHas('production_cost_batches', ['id' => $id, 'unit_cost_uah' => 30, 'version' => 3]);
        $this->assertDatabaseCount('production_cost_batch_revisions', 3);
    }

    public function test_old_area_snapshots_recalculate_on_read_without_rewriting_user_data(): void
    {
        $this->owner();
        $payload = $this->localPayload();
        $id = $this->postJson(self::URL, $payload)->assertCreated()->json('id');
        $row = DB::table('production_cost_batches')->where('id', $id)->first();
        $inputs = json_decode($row->inputs, true);
        unset($inputs['cut_length_cm']);
        DB::table('production_cost_batches')->where('id', $id)->update(['inputs' => json_encode($inputs), 'unit_cost_uah' => 4.5]);
        $before = DB::table('production_cost_batches')->where('id', $id)->first();
        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.0.inputs.cut_length_cm', '100.00')->assertJsonPath('data.0.calculation.unit_cost_uah', 5);
        $this->postJson(self::URL, $payload)->assertCreated()->assertJsonPath('id', $id);
        $this->assertEquals($before, DB::table('production_cost_batches')->where('id', $id)->first());
        $this->assertDatabaseCount('production_cost_batch_revisions', 1);

        // Старий розкрій за площею міг допускати відріз, з якого не виходить цілої пари.
        $inputs = array_replace($inputs, ['fabric_length' => '0.20', 'fabric_width_cm' => '10']);
        DB::table('production_cost_batches')->where('id', $id)->update(['inputs' => json_encode($inputs)]);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.0.calculation.layout.total_pieces', 1)->assertJsonPath('data.0.calculation.unit_cost_uah', null);
    }

    public function test_an_unpaired_piece_is_not_sold_as_a_fractional_pair_and_no_pairs_are_rejected(): void
    {
        $this->owner();
        $payload = $this->localPayload(['fabric_length' => '1', 'fabric_width_cm' => '8', 'top_width_cm' => '20', 'bottom_width_cm' => '13', 'height_cm' => '8', 'goods_uah' => '100']);
        $this->postJson(self::URL, $payload)->assertCreated()->assertJsonPath('calculation.layout.total_pieces', 5)
            ->assertJsonPath('calculation.layout.pairs', 2)->assertJsonPath('calculation.layout.unpaired_pieces', 1)->assertJsonPath('calculation.unit_cost_uah', 50);
        $this->postJson(self::URL, array_replace($payload, ['request_key' => (string) Str::uuid(), 'fabric_length' => '0.2']))->assertUnprocessable()->assertJsonValidationErrors('fabric_length');
    }

    public function test_local_purchase_uses_only_uah_and_no_currency_or_commission(): void
    {
        $this->owner();
        $response = $this->postJson(self::URL, $this->localPayload(['goods_uah' => '3000,00', 'ukraine_shipping_uah' => '100,00', 'other_costs_uah' => '20']))->assertCreated()
            ->assertJsonPath('inputs.purchase_source', 'ukraine')->assertJsonPath('inputs.goods_uah', '3000.00')
            ->assertJsonPath('calculation.total_uah', 3120)->assertJsonPath('calculation.unit_cost_uah', 5.2)
            ->assertJsonPath('calculation.linear_metre_cost_uah', 312)->assertJsonPath('calculation.square_metre_cost_uah', 156)
            ->assertJsonCount(3, 'calculation.breakdown')->assertJsonPath('calculation.breakdown.0.total_uah', 3000)
            ->assertJsonPath('calculation.ukraine_shipping_included', true);
        foreach (['goods_cny', 'cny_rate', 'usd_rate', 'commission_percent'] as $field) {
            $this->assertArrayNotHasKey($field, $response->json('inputs'));
        }
        $this->assertArrayNotHasKey('commission_cny', $response->json('calculation'));
        $this->assertDatabaseCount('sole_inventory_batches', 0);
    }

    public function test_local_delivery_unknown_zero_and_yards_remain_distinct(): void
    {
        $this->owner();
        $this->postJson(self::URL, $this->localPayload())->assertCreated()
            ->assertJsonPath('inputs.ukraine_shipping_uah', null)->assertJsonPath('calculation.ukraine_shipping_included', false)
            ->assertJsonPath('calculation.unit_cost_uah', 5);
        $this->postJson(self::URL, $this->localPayload(['ukraine_shipping_uah' => '0']))->assertCreated()->assertJsonPath('calculation.ukraine_shipping_included', true);
        $this->postJson(self::URL, $this->localPayload(['goods_uah' => '0.10', 'other_costs_uah' => '0.20']))->assertCreated()->assertJsonPath('calculation.total_uah', 0.3);
        $response = $this->postJson(self::URL, $this->localPayload(['length_unit' => 'yard']))->assertCreated()->assertJsonPath('calculation.unit_cost_uah', 5.555556);
        $this->assertEqualsWithDelta(9.144, $response->json('calculation.length_metres'), 0.00000001);
    }

    public function test_source_specific_validation_rejects_hidden_foreign_costs(): void
    {
        $this->owner();
        foreach ([['purchase_source', 'unknown'], ['purchase_source', ''], ['goods_uah', ''], ['goods_uah', '-1'], ['goods_uah', '2.001'], ['goods_uah', '1e2'], ['goods_uah', '1000001'],
            ['goods_cny', '100'], ['china_shipping_cny', '1'], ['commission_percent', '10'], ['international_shipping_usd', '2'], ['cny_rate', '7'], ['usd_rate', '40']] as [$field, $value]) {
            $this->postJson(self::URL, $this->localPayload([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $missing = $this->localPayload();
        unset($missing['goods_uah']);
        $this->postJson(self::URL, $missing)->assertUnprocessable()->assertJsonValidationErrors('goods_uah');
        $this->postJson(self::URL, $this->payload(['goods_uah' => '100']))->assertUnprocessable()->assertJsonValidationErrors('goods_uah');
        $this->assertDatabaseCount('production_cost_batches', 0);
    }

    public function test_source_edits_are_versioned_and_old_china_records_are_read_without_rewrite(): void
    {
        $this->owner();
        $china = $this->payload();
        $id = $this->postJson(self::URL, $china)->assertCreated()->json('id');
        $row = DB::table('production_cost_batches')->where('id', $id)->first();
        $inputs = json_decode($row->inputs, true);
        unset($inputs['purchase_source']);
        DB::table('production_cost_batches')->where('id', $id)->update(['inputs' => json_encode($inputs)]);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.0.inputs.purchase_source', 'china')->assertJsonPath('data.0.calculation.total_uah', 1126);
        $this->assertArrayNotHasKey('purchase_source', json_decode(DB::table('production_cost_batches')->where('id', $id)->value('inputs'), true));
        $this->postJson(self::URL, $china)->assertCreated()->assertJsonPath('id', $id);

        $local = $this->localPayload(['version' => 1]);
        unset($local['request_key']);
        $this->putJson(self::URL.'/'.$id, $local)->assertOk()->assertJsonPath('version', 2)->assertJsonPath('calculation.total_uah', 3000);
        $this->putJson(self::URL.'/'.$id, $local)->assertConflict();
        $stored = json_decode(DB::table('production_cost_batches')->where('id', $id)->value('inputs'), true);
        $this->assertArrayNotHasKey('goods_cny', $stored);
        $this->assertSame('ukraine', $stored['purchase_source']);

        $china['version'] = 2;
        unset($china['request_key']);
        $this->putJson(self::URL.'/'.$id, $china)->assertOk()->assertJsonPath('version', 3)->assertJsonPath('inputs.purchase_source', 'china')->assertJsonPath('calculation.total_uah', 1126);
        $this->assertDatabaseCount('production_cost_batches', 1);
        $this->assertDatabaseCount('production_cost_batch_revisions', 3);
    }

    public function test_local_import_and_api_retries_do_not_duplicate_batches(): void
    {
        Storage::fake('local');
        $payload = $this->localPayload();
        Storage::disk('local')->put('local-fur.json', json_encode($payload));
        $path = Storage::disk('local')->path('local-fur.json');
        $this->artisan('production-costs:import-fur', ['--file' => $path])->assertSuccessful();
        $this->artisan('production-costs:import-fur', ['--file' => $path])->assertSuccessful();
        $this->owner();
        $this->postJson(self::URL, $payload)->assertCreated()->assertJsonPath('inputs.purchase_source', 'ukraine');
        $this->postJson(self::URL, array_replace($payload, ['goods_uah' => '3001']))->assertConflict();
        $this->assertDatabaseCount('production_cost_batches', 1);
        $this->assertDatabaseCount('production_cost_batch_revisions', 1);
    }

    public function test_owner_only_routes_and_get_has_no_side_effects(): void
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
        $this->getJson(self::URL)->assertOk()->assertJsonPath('total', 0)->assertJsonPath('data', []);
        $this->getJson(self::URL.'?page=0')->assertUnprocessable();
        $this->assertDatabaseCount('production_cost_batches', 0);
        $this->assertDatabaseCount('production_cost_batch_revisions', 0);
    }

    public function test_metre_price_includes_commission_and_shipping_and_uses_two_trapezoids(): void
    {
        $this->owner();
        $this->postJson(self::URL, $this->payload())->assertCreated()->assertJsonPath('quantity', 1)
            ->assertJsonPath('inputs.fabric_length', '10.0000')->assertJsonPath('inputs.length_unit', 'metre')
            ->assertJsonPath('inputs.ukraine_shipping_uah', null)->assertJsonPath('purchased_on', null)
            ->assertJsonPath('calculation.commission_cny', 11)->assertJsonPath('calculation.total_cny', 121)
            ->assertJsonPath('calculation.total_uah', 1126)->assertJsonPath('calculation.length_metres', 10)
            ->assertJsonPath('calculation.total_area_m2', 20)->assertJsonPath('calculation.pair_area_m2', 0.03)
            ->assertJsonPath('calculation.linear_metre_cost_uah', 112.6)->assertJsonPath('calculation.square_metre_cost_uah', 56.3)
            ->assertJsonPath('calculation.unit_cost_uah', 1.876667)->assertJsonPath('calculation.breakdown.0.label', 'Хутро')
            ->assertJsonPath('calculation.breakdown.0.unit_uah', 1)->assertJsonPath('calculation.ukraine_shipping_included', false);
        $this->assertDatabaseCount('sole_inventory_batches', 0);
        $this->assertDatabaseCount('production_cost_batch_revisions', 1);
    }

    public function test_yards_are_converted_exactly_and_fractional_lengths_are_preserved(): void
    {
        $this->owner();
        $response = $this->postJson(self::URL, $this->payload(['length_unit' => 'yard']))->assertCreated()
            ->assertJsonPath('calculation.unit_cost_uah', 2.085185)->assertJsonPath('calculation.total_uah', 1126);
        $this->assertEqualsWithDelta(9.144, $response->json('calculation.length_metres'), 0.00000001);
        $this->assertEqualsWithDelta(18.288, $response->json('calculation.total_area_m2'), 0.00000001);
        $response = $this->postJson(self::URL, $this->payload(['length_unit' => 'yard', 'fabric_length' => '10,1250', 'cny_rate' => '6,0000']))->assertCreated()
            ->assertJsonPath('inputs.fabric_length', '10.1250');
        $this->assertEqualsWithDelta(10.125 * 0.9144, $response->json('calculation.length_metres'), 0.00000001);
    }

    public function test_unknown_domestic_delivery_is_not_reported_as_free_and_currency_rounding_matches_soles(): void
    {
        $this->owner();
        $this->postJson(self::URL, $this->payload(['ukraine_shipping_uah' => '100,00', 'other_costs_uah' => '20']))->assertCreated()
            ->assertJsonPath('calculation.total_uah', 1246)->assertJsonPath('calculation.unit_cost_uah', 2.076667)
            ->assertJsonPath('calculation.ukraine_shipping_included', true);
        $this->postJson(self::URL, $this->payload(['ukraine_shipping_uah' => '0']))->assertCreated()->assertJsonPath('calculation.ukraine_shipping_included', true);
        $this->postJson(self::URL, $this->payload(['ukraine_shipping_uah' => '']))->assertCreated()->assertJsonPath('inputs.ukraine_shipping_uah', null);
        $payload = $this->payload(['goods_cny' => '0.05', 'china_shipping_cny' => '0', 'international_shipping_usd' => '0', 'cny_rate' => '7.1234']);
        unset($payload['ukraine_shipping_uah']);
        $this->postJson(self::URL, $payload)->assertCreated()->assertJsonPath('calculation.commission_cny', 0.01)
            ->assertJsonPath('calculation.total_uah', 0.43)->assertJsonPath('calculation.unit_cost_uah', 0.000717);
    }

    public function test_invalid_lengths_geometry_and_client_totals_are_rejected(): void
    {
        $this->owner();
        foreach ([['fabric_length', 0], ['fabric_length', '1.00001'], ['fabric_length', '1e2'], ['fabric_length', 1000001],
            ['length_unit', 'cm'], ['length_unit', ''], ['fabric_width_cm', 0], ['fabric_width_cm', 9], ['height_cm', 0], ['height_cm', 1001],
            ['cut_length_cm', 0], ['cut_length_cm', ''], ['cut_length_cm', 19], ['cut_length_cm', '100.001'], ['cut_length_cm', '1e2'], ['cut_length_cm', 1001],
            ['top_width_cm', -1], ['bottom_width_cm', 0], ['goods_cny', '1.001'], ['commission_percent', 101], ['usd_rate', 0],
            ['ukraine_shipping_uah', -1], ['purchased_on', '2026-02-30'], ['name', ''], ['quantity', 10], ['total_uah', 1], ['unit_cost_uah', 1], ['component', 'soles'], ['layout', ['pairs' => 1]], ['total_pieces', 110], ['pairs', 55]] as [$field, $value]) {
            $this->postJson(self::URL, $this->payload([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson(self::URL, $this->payload(['fabric_length' => '0.05']))->assertUnprocessable()->assertJsonValidationErrors('fabric_length');
        $this->postJson(self::URL, $this->payload(['fabric_length' => 1, 'fabric_width_cm' => 1, 'top_width_cm' => 1, 'bottom_width_cm' => 1, 'height_cm' => 100,
            'goods_cny' => 1000000, 'china_shipping_cny' => 1000000, 'commission_percent' => 100, 'international_shipping_usd' => 1000000,
            'ukraine_shipping_uah' => 1000000, 'other_costs_uah' => 1000000, 'cny_rate' => 1000, 'usd_rate' => 1000]))->assertUnprocessable()->assertJsonValidationErrors('fabric_width_cm');
        $this->assertDatabaseCount('production_cost_batches', 0);
    }

    public function test_idempotent_creation_versioned_edits_and_new_batches_remain_independent(): void
    {
        $this->owner();
        $payload = $this->payload();
        $id = $this->postJson(self::URL, $payload)->assertCreated()->json('id');
        $this->postJson(self::URL, array_replace($payload, ['fabric_length' => '10.0000']))->assertCreated()->assertJsonPath('id', $id);
        $this->postJson(self::URL, array_replace($payload, ['length_unit' => 'yard']))->assertConflict();
        $edit = array_replace($payload, ['version' => 1, 'length_unit' => 'yard']);
        unset($edit['request_key']);
        $this->putJson(self::URL.'/'.$id, $edit)->assertOk()->assertJsonPath('version', 2)->assertJsonPath('calculation.unit_cost_uah', 2.085185);
        $this->putJson(self::URL.'/'.$id, $edit)->assertConflict();
        $this->putJson(self::URL.'/999999', $edit)->assertNotFound();
        $this->postJson(self::URL, $this->payload(['goods_cny' => 200]))->assertCreated();
        $this->assertDatabaseHas('production_cost_batches', ['id' => $id, 'total_uah' => 1126, 'version' => 2]);
        $this->assertDatabaseCount('production_cost_batch_revisions', 3);
    }

    public function test_fur_cannot_change_other_materials_or_reuse_their_request_keys(): void
    {
        $this->owner();
        $key = (string) Str::uuid();
        $foam = app(ProductionCostService::class)->save(['request_key' => $key, 'name' => 'Тестова вставка',
            'sheet_price_usd' => 4.5, 'usd_rate' => 40, 'sheet_length_cm' => 120, 'sheet_width_cm' => 200,
            'blank_length_cm' => 25, 'blank_width_cm' => 10], null, null, 'foam');
        $this->postJson(self::URL, $this->payload(['request_key' => $key]))->assertConflict();
        $edit = $this->payload(['version' => 1]);
        unset($edit['request_key']);
        $this->putJson(self::URL.'/'.$foam['id'], $edit)->assertNotFound();
        $this->getJson(self::URL)->assertOk()->assertJsonPath('total', 0);
        $this->assertDatabaseHas('production_cost_batches', ['id' => $foam['id'], 'component' => 'foam', 'total_uah' => 180]);
    }

    public function test_private_import_preserves_future_edits_and_rejects_bad_geometry(): void
    {
        Storage::fake('local');
        $payload = $this->payload();
        Storage::disk('local')->put('fur.json', json_encode($payload));
        $path = Storage::disk('local')->path('fur.json');
        $this->artisan('production-costs:import-fur', ['--file' => $path])->assertSuccessful();
        $this->artisan('production-costs:import-fur', ['--file' => $path])->assertSuccessful();
        $id = DB::table('production_cost_batches')->value('id');
        $this->owner();
        $edit = array_replace($payload, ['usd_rate' => 41, 'version' => 1]);
        unset($edit['request_key']);
        $this->putJson(self::URL.'/'.$id, $edit)->assertOk();
        $this->artisan('production-costs:import-fur', ['--file' => $path])->assertSuccessful();
        $this->assertDatabaseHas('production_cost_batches', ['id' => $id, 'version' => 2, 'total_uah' => 1136, 'user_id' => null]);
        Storage::disk('local')->put('fur.json', json_encode($this->payload(['fabric_width_cm' => 1])));
        $this->artisan('production-costs:import-fur', ['--file' => $path])->assertFailed();
        $this->assertDatabaseCount('production_cost_batches', 1);
        $this->assertDatabaseCount('production_cost_batch_revisions', 2);
        $this->assertDatabaseCount('sole_inventory_batches', 0);
    }

    public function test_history_is_paginated_and_shows_latest_batches_first(): void
    {
        $this->owner();
        for ($i = 1; $i <= 21; $i++) {
            $this->postJson(self::URL, $this->payload(['name' => 'Хутро '.$i]))->assertCreated();
        }
        $this->getJson(self::URL)->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('data.0.name', 'Хутро 21');
        $this->getJson(self::URL.'?page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 21);
    }
}
