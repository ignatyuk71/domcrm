<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Services\Costs\ProductionCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FoamCostsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/production-costs/foam-calculations';

    private function payload(array $replace = []): array
    {
        // Синтетичні ціни та розміри, без приватних закупівель власника.
        return array_replace(['request_key' => (string) Str::uuid(), 'name' => 'Тестова вставка', 'purchased_on' => null,
            'sheet_price_usd' => '4.50', 'usd_rate' => '40', 'shipping_uah' => null, 'sheet_length_cm' => '120',
            'sheet_width_cm' => '200', 'blank_length_cm' => '25', 'blank_width_cm' => '10', 'note' => null], $replace);
    }

    private function owner(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    }

    public function test_foam_is_owner_only_and_empty_get_does_not_seed_anything(): void
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
        $this->getJson(self::URL)->assertOk()->assertJsonPath('data', [])->assertJsonPath('total', 0);
        $this->getJson(self::URL.'?page=0')->assertUnprocessable();
        $this->assertDatabaseCount('production_cost_batches', 0);
        $this->assertDatabaseCount('production_cost_batch_revisions', 0);
    }

    public function test_price_of_one_sheet_yields_two_standalone_inserts_without_purchase_quantity(): void
    {
        $this->owner();
        $this->postJson(self::URL, $this->payload())->assertCreated()->assertJsonPath('quantity', 1)
            ->assertJsonPath('purchased_on', null)->assertJsonPath('inputs.sheet_price_usd', '4.50')
            ->assertJsonPath('inputs.usd_rate', '40.0000')->assertJsonPath('inputs.shipping_uah', null)
            ->assertJsonPath('calculation.total_uah', 180)->assertJsonPath('calculation.sheet_cost_uah', 180)
            ->assertJsonPath('calculation.purchase_sheet_uah', 180)->assertJsonPath('calculation.sheet_area_m2', 2.4)
            ->assertJsonPath('calculation.square_metre_cost_uah', 75)->assertJsonPath('calculation.pair_area_m2', 0.05)
            ->assertJsonPath('calculation.unit_cost_uah', 3.75)->assertJsonPath('calculation.shipping_included', false);
        $this->assertDatabaseCount('production_cost_batch_revisions', 1);
        $this->assertDatabaseCount('sole_inventory_batches', 0);
        $this->getJson('/api/production-costs/cardboard-batches')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/production-costs/sole-batches')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_currency_commas_and_optional_per_sheet_delivery(): void
    {
        $this->owner();
        $this->postJson(self::URL, $this->payload(['sheet_price_usd' => '4,50', 'usd_rate' => '40,0000', 'shipping_uah' => '12,00']))
            ->assertCreated()->assertJsonPath('calculation.total_uah', 192)->assertJsonPath('calculation.unit_cost_uah', 4)
            ->assertJsonPath('calculation.shipping_included', true);
        $this->postJson(self::URL, $this->payload(['shipping_uah' => '0']))->assertCreated()->assertJsonPath('calculation.shipping_included', true);
        $this->postJson(self::URL, $this->payload(['shipping_uah' => '']))->assertCreated()->assertJsonPath('inputs.shipping_uah', null);
        $this->postJson(self::URL, $this->payload(['sheet_price_usd' => '0.01', 'usd_rate' => '1.5000']))->assertCreated()
            ->assertJsonPath('calculation.purchase_sheet_uah', 0.02)->assertJsonPath('calculation.unit_cost_uah', 0.000417);
    }

    public function test_invalid_inputs_client_totals_and_invented_purchase_quantity_are_rejected(): void
    {
        $this->owner();
        foreach ([['sheet_price_usd', ''], ['sheet_price_usd', -1], ['sheet_price_usd', '1.001'], ['sheet_price_usd', '1e3'],
            ['sheet_price_usd', 1001], ['usd_rate', 0], ['usd_rate', 1001], ['usd_rate', '1.00001'], ['usd_rate', '1e2'],
            ['sheet_width_cm', 0], ['sheet_length_cm', 1001], ['blank_length_cm', 201], ['shipping_uah', -1],
            ['purchased_on', '2026-02-30'], ['name', ''], ['total_uah', 1], ['unit_cost_uah', 1], ['component', 'soles'],
            ['quantity', 70], ['goods_uah', 100]] as [$field, $value]) {
            $this->postJson(self::URL, $this->payload([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('production_cost_batches', 0);
        $this->postJson(self::URL, $this->payload(['blank_length_cm' => 180, 'blank_width_cm' => 110]))->assertCreated();
    }

    public function test_retries_edits_and_new_prices_keep_separate_audited_snapshots(): void
    {
        $this->owner();
        $payload = $this->payload();
        $id = $this->postJson(self::URL, $payload)->assertCreated()->json('id');
        $this->postJson(self::URL, array_replace($payload, ['usd_rate' => '40.0000']))->assertCreated()->assertJsonPath('id', $id);
        $this->postJson(self::URL, array_replace($payload, ['usd_rate' => 41]))->assertConflict();
        $edit = array_replace($payload, ['version' => 1, 'usd_rate' => '41']);
        unset($edit['request_key']);
        $this->putJson(self::URL.'/'.$id, $edit)->assertOk()->assertJsonPath('version', 2)->assertJsonPath('calculation.unit_cost_uah', 3.84375);
        $this->putJson(self::URL.'/'.$id, $edit)->assertConflict();
        $this->putJson(self::URL.'/999999', $edit)->assertNotFound();
        $this->postJson(self::URL, $this->payload(['sheet_price_usd' => 5]))->assertCreated();
        $this->assertDatabaseHas('production_cost_batches', ['id' => $id, 'total_uah' => 184.50, 'version' => 2]);
        $this->assertDatabaseCount('production_cost_batches', 2);
        $this->assertDatabaseCount('production_cost_batch_revisions', 3);
        $this->assertDatabaseCount('sole_inventory_batches', 0);
    }

    public function test_foam_cannot_overwrite_cardboard_or_reuse_its_request_key(): void
    {
        $this->owner();
        $key = (string) Str::uuid();
        $cardboard = app(ProductionCostService::class)->save([
            'request_key' => $key, 'name' => 'Тестовий картон', 'quantity' => 10, 'goods_uah' => 1000,
            'sheet_length_cm' => 120, 'sheet_width_cm' => 80, 'blank_length_cm' => 25, 'blank_width_cm' => 9,
        ], null, null, 'cardboard');
        $this->postJson(self::URL, $this->payload(['request_key' => $key]))->assertConflict();
        $edit = $this->payload(['version' => 1]);
        unset($edit['request_key']);
        $this->putJson(self::URL.'/'.$cardboard['id'], $edit)->assertNotFound();
        $foamId = $this->postJson(self::URL, $this->payload())->assertCreated()->json('id');
        $this->putJson('/api/production-costs/cardboard-batches/'.$foamId, [
            'version' => 1, 'name' => 'Інший матеріал', 'quantity' => 10, 'goods_uah' => 1000,
            'sheet_length_cm' => 120, 'sheet_width_cm' => 80, 'blank_length_cm' => 25, 'blank_width_cm' => 9,
        ])->assertNotFound();
        $this->assertDatabaseHas('production_cost_batches', ['id' => $cardboard['id'], 'component' => 'cardboard', 'total_uah' => 1000]);
    }

    public function test_private_import_is_idempotent_respects_later_edits_and_checks_geometry(): void
    {
        Storage::fake('local');
        $payload = $this->payload();
        Storage::disk('local')->put('foam.json', json_encode($payload));
        $path = Storage::disk('local')->path('foam.json');
        $this->artisan('production-costs:import-foam', ['--file' => $path])->assertSuccessful();
        $this->artisan('production-costs:import-foam', ['--file' => $path])->assertSuccessful();
        $id = DB::table('production_cost_batches')->value('id');
        $this->owner();
        $edit = array_replace($payload, ['usd_rate' => '41', 'version' => 1]);
        unset($edit['request_key']);
        $this->putJson(self::URL.'/'.$id, $edit)->assertOk();
        $this->artisan('production-costs:import-foam', ['--file' => $path])->assertSuccessful();
        $this->assertDatabaseHas('production_cost_batches', ['id' => $id, 'total_uah' => 184.50, 'version' => 2, 'user_id' => null]);
        Storage::disk('local')->put('foam.json', json_encode($this->payload(['blank_width_cm' => 250])));
        $this->artisan('production-costs:import-foam', ['--file' => $path])->assertFailed();
        $this->assertDatabaseCount('production_cost_batches', 1);
        $this->assertDatabaseCount('production_cost_batch_revisions', 2);
    }

    public function test_price_history_is_paginated_and_material_scoped(): void
    {
        $this->owner();
        for ($i = 1; $i <= 21; $i++) {
            $this->postJson(self::URL, $this->payload(['name' => 'Ціна '.$i]))->assertCreated();
        }
        $this->getJson(self::URL)->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('data.0.name', 'Ціна 21');
        $this->getJson(self::URL.'?page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 21);
    }
}
