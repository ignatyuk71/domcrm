<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Services\Costs\ProductionCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CardboardCostsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/production-costs/cardboard-batches';

    private function payload(array $replace = []): array
    {
        // Синтетичний приклад, не рахунок власника.
        return array_replace(['request_key' => (string) Str::uuid(), 'name' => 'Тестовий картон', 'purchased_on' => null,
            'quantity' => 20, 'goods_uah' => '3600', 'shipping_uah' => null, 'sheet_length_cm' => '120', 'sheet_width_cm' => '80',
            'blank_length_cm' => '25', 'blank_width_cm' => '9', 'note' => null], $replace);
    }

    private function owner(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    }

    public function test_cardboard_endpoints_require_owner(): void
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
    }

    public function test_read_only_empty_list_does_not_invent_prices_or_stock(): void
    {
        $this->owner();
        $this->getJson(self::URL)->assertOk()->assertJsonPath('data', [])->assertJsonPath('total', 0);
        $this->getJson(self::URL.'?page=0')->assertUnprocessable();
        $this->assertDatabaseCount('production_cost_batches', 0);
        $this->assertDatabaseCount('production_cost_batch_revisions', 0);
        $this->assertDatabaseCount('sole_inventory_batches', 0);
    }

    public function test_prices_use_two_rectangles_without_intermediate_rounding(): void
    {
        $this->owner();
        $this->postJson(self::URL, $this->payload())->assertCreated()
            ->assertJsonPath('inputs.goods_uah', '3600.00')->assertJsonPath('inputs.shipping_uah', null)
            ->assertJsonPath('purchased_on', null)->assertJsonPath('calculation.total_uah', 3600)
            ->assertJsonPath('calculation.sheet_cost_uah', 180)->assertJsonPath('calculation.square_metre_cost_uah', 187.5)
            ->assertJsonPath('calculation.sheet_area_m2', 0.96)->assertJsonPath('calculation.pair_area_m2', 0.045)
            ->assertJsonPath('calculation.unit_cost_uah', 8.4375)->assertJsonPath('calculation.shipping_included', false);
        $this->getJson('/api/production-costs/sole-batches')->assertOk()->assertJsonPath('total', 0);
        $this->assertDatabaseCount('production_cost_batch_revisions', 1);
        $this->assertDatabaseCount('sole_inventory_batches', 0);
    }

    public function test_delivery_comma_input_and_explicit_zero_are_distinct_from_unknown(): void
    {
        $this->owner();
        $this->postJson(self::URL, $this->payload(['shipping_uah' => '240,00', 'sheet_width_cm' => '80,00']))->assertCreated()
            ->assertJsonPath('calculation.total_uah', 3840)->assertJsonPath('calculation.unit_cost_uah', 9)
            ->assertJsonPath('calculation.shipping_included', true);
        $this->postJson(self::URL, $this->payload(['shipping_uah' => '0']))->assertCreated()->assertJsonPath('inputs.shipping_uah', '0.00')
            ->assertJsonPath('calculation.shipping_included', true);
        $this->postJson(self::URL, $this->payload(['shipping_uah' => '']))->assertCreated()->assertJsonPath('inputs.shipping_uah', null);
        $payload = $this->payload();
        unset($payload['shipping_uah']);
        $this->postJson(self::URL, $payload)->assertCreated()->assertJsonPath('inputs.shipping_uah', null);
    }

    public function test_invalid_sizes_and_client_totals_are_rejected(): void
    {
        $this->owner();
        foreach ([['quantity', 0], ['quantity', '2.5'], ['goods_uah', -1], ['goods_uah', '1e3'], ['goods_uah', '1.001'],
            ['goods_uah', 1000001], ['sheet_width_cm', 0], ['sheet_length_cm', ''], ['sheet_length_cm', 1001],
            ['blank_length_cm', 121], ['blank_width_cm', 121], ['blank_width_cm', '1.005'], ['shipping_uah', -1],
            ['purchased_on', '2026-02-30'], ['name', ''], ['total_uah', 1], ['unit_cost_uah', 1], ['component', 'soles']] as [$field, $value]) {
            $response = $this->postJson(self::URL, $this->payload([$field => $value]))->assertUnprocessable();
            $response->assertJsonValidationErrors($field === 'blank_width_cm' && $value === 121 ? 'blank_length_cm' : $field);
        }
        $this->assertDatabaseCount('production_cost_batches', 0);
        // Заготовка поміщається після повороту, її не потрібно помилково відхиляти.
        $this->postJson(self::URL, $this->payload(['blank_length_cm' => 70, 'blank_width_cm' => 100]))->assertCreated();
    }

    public function test_retries_versions_and_separate_snapshots(): void
    {
        $this->owner();
        $payload = $this->payload();
        $id = $this->postJson(self::URL, $payload)->assertCreated()->json('id');
        $this->postJson(self::URL, array_replace($payload, ['goods_uah' => '3600.00']))->assertCreated()->assertJsonPath('id', $id);
        $this->postJson(self::URL, array_replace($payload, ['quantity' => 21]))->assertConflict();
        $edit = array_replace($payload, ['version' => 1, 'goods_uah' => '4000']);
        unset($edit['request_key']);
        $this->putJson(self::URL.'/'.$id, $edit)->assertOk()->assertJsonPath('version', 2)->assertJsonPath('calculation.unit_cost_uah', 9.375);
        $this->putJson(self::URL.'/'.$id, $edit)->assertConflict();
        $this->putJson(self::URL.'/999999', $edit)->assertNotFound();
        $this->postJson(self::URL, $this->payload(['goods_uah' => '5000']))->assertCreated();
        $this->assertDatabaseHas('production_cost_batches', ['id' => $id, 'total_uah' => 4000, 'version' => 2]);
        $this->assertDatabaseCount('production_cost_batches', 2);
        $this->assertDatabaseCount('production_cost_batch_revisions', 3);
    }

    public function test_other_material_cannot_be_overwritten_or_retried_as_cardboard(): void
    {
        $this->owner();
        $sole = app(ProductionCostService::class)->save([
            'request_key' => (string) Str::uuid(), 'name' => 'Тестова підошва', 'quantity' => 10,
            'goods_cny' => 100, 'china_shipping_cny' => 0, 'commission_percent' => 0, 'international_shipping_usd' => 0,
            'ukraine_shipping_uah' => 0, 'other_costs_uah' => 0, 'cny_rate' => 6, 'usd_rate' => 40,
        ], null);
        $key = DB::table('production_cost_batches')->where('id', $sole['id'])->value('request_key');
        $this->postJson(self::URL, $this->payload(['request_key' => $key]))->assertConflict();
        $edit = $this->payload(['version' => 1]);
        unset($edit['request_key']);
        $this->putJson(self::URL.'/'.$sole['id'], $edit)->assertNotFound();
        $this->getJson(self::URL)->assertOk()->assertJsonPath('total', 0);
        $this->assertDatabaseHas('production_cost_batches', ['id' => $sole['id'], 'component' => 'soles', 'total_uah' => 600]);
    }

    public function test_private_import_is_idempotent_and_validates_geometry(): void
    {
        Storage::fake('local');
        $payload = $this->payload();
        Storage::disk('local')->put('cardboard.json', json_encode($payload));
        $path = Storage::disk('local')->path('cardboard.json');
        $this->artisan('production-costs:import-cardboard', ['--file' => $path])->assertSuccessful();
        $this->artisan('production-costs:import-cardboard', ['--file' => $path])->assertSuccessful();
        $id = DB::table('production_cost_batches')->value('id');
        $this->owner();
        $edit = array_replace($payload, ['goods_uah' => '4000', 'version' => 1]);
        unset($edit['request_key']);
        $this->putJson(self::URL.'/'.$id, $edit)->assertOk();
        $this->artisan('production-costs:import-cardboard', ['--file' => $path])->assertSuccessful();
        $this->assertDatabaseHas('production_cost_batches', ['id' => $id, 'total_uah' => 4000, 'version' => 2, 'user_id' => null]);
        Storage::disk('local')->put('cardboard.json', json_encode($this->payload(['blank_width_cm' => 200])));
        $this->artisan('production-costs:import-cardboard', ['--file' => $path])->assertFailed();
        $this->assertDatabaseCount('production_cost_batches', 1);
        $this->assertDatabaseCount('production_cost_batch_revisions', 2);
        $this->assertDatabaseCount('sole_inventory_batches', 0);
    }

    public function test_cardboard_history_is_paginated(): void
    {
        $this->owner();
        for ($i = 1; $i <= 21; $i++) {
            $this->postJson(self::URL, $this->payload(['name' => 'Картон '.$i]))->assertCreated();
        }
        $this->getJson(self::URL)->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('data.0.name', 'Картон 21');
        $this->getJson(self::URL.'?page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 21);
    }
}
