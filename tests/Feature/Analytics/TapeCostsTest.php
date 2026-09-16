<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Services\Costs\ProductionCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class TapeCostsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/production-costs/tape-batches';

    private function payload(array $replace = []): array
    {
        // Вигадані розцінки: справжні рахунки залишаються лише у приватній БД.
        return array_replace(['request_key' => (string) Str::uuid(), 'name' => 'Тестова стрічка', 'purchased_on' => null, 'note' => null,
            'length_m' => '100', 'goods_uah' => '1000', 'shipping_uah' => '200', 'per_slipper_cm' => '75', 'allowance_cm' => '5'], $replace);
    }

    private function owner(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    }

    public function test_only_owner_can_read_or_write_and_empty_reads_do_not_seed(): void
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

    public function test_purchase_in_hryvnias_and_allowance_on_each_slipper_are_counted_once(): void
    {
        $this->owner();
        $id = $this->postJson(self::URL, $this->payload(['length_m' => '100,0000', 'allowance_cm' => '5,00']))->assertCreated()
            ->assertJsonPath('quantity', 1)->assertJsonPath('purchased_on', null)->assertJsonPath('inputs.length_m', '100.0000')
            ->assertJsonPath('calculation.total_uah', 1200)->assertJsonPath('calculation.metre_cost_uah', 12)
            ->assertJsonPath('calculation.slipper_length_cm', 80)->assertJsonPath('calculation.pair_length_m', 1.6)
            ->assertJsonPath('calculation.unit_cost_uah', 19.2)->assertJsonPath('calculation.slipper_cost_uah', 9.6)
            ->assertJsonPath('calculation.whole_pairs', 62)->assertJsonPath('calculation.remaining_length_m', 0.8)
            ->assertJsonPath('calculation.breakdown.0.unit_uah', 16)->assertJsonPath('calculation.breakdown.1.unit_uah', 3.2)->json('id');
        $before = DB::table('production_cost_batches')->where('id', $id)->first();
        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.0.calculation.unit_cost_uah', 19.2);
        $this->assertEquals($before, DB::table('production_cost_batches')->where('id', $id)->first());
        $this->assertDatabaseCount('production_cost_batch_revisions', 1);
        $this->assertDatabaseCount('sole_inventory_batches', 0);
    }

    public function test_unknown_shipping_and_known_zero_are_different(): void
    {
        $this->owner();
        $payload = $this->payload();
        unset($payload['shipping_uah']);
        $this->postJson(self::URL, $payload)->assertCreated()->assertJsonPath('inputs.shipping_uah', null)
            ->assertJsonPath('calculation.shipping_included', false)->assertJsonPath('calculation.total_uah', 1000)
            ->assertJsonPath('calculation.breakdown.1.total_uah', null)->assertJsonPath('calculation.breakdown.1.unit_uah', null);
        $this->postJson(self::URL, $this->payload(['shipping_uah' => '']))->assertCreated()->assertJsonPath('inputs.shipping_uah', null);
        $this->postJson(self::URL, $this->payload(['shipping_uah' => 0]))->assertCreated()->assertJsonPath('calculation.shipping_included', true);
    }

    public function test_bad_values_and_client_totals_are_rejected(): void
    {
        $this->owner();
        foreach ([['length_m', ''], ['length_m', 0], ['length_m', '1.5999'], ['length_m', '1e2'], ['length_m', '1.00001'], ['length_m', 1000001],
            ['goods_uah', ''], ['goods_uah', -1], ['goods_uah', '1.001'], ['goods_uah', 1000001], ['shipping_uah', -1], ['shipping_uah', 1000001],
            ['per_slipper_cm', 0], ['per_slipper_cm', 1001], ['allowance_cm', ''], ['allowance_cm', -1], ['allowance_cm', '1.001'],
            ['name', ''], ['purchased_on', '2026-02-30'], ['quantity', 1], ['component', 'soles'], ['total_uah', 1], ['unit_cost_uah', 1],
            ['metre_cost_uah', 1], ['slipper_cost_uah', 1], ['pair_length_m', 1], ['whole_pairs', 1], ['remaining_length_m', 1], ['breakdown', [1]]] as [$field, $value]) {
            $this->postJson(self::URL, $this->payload([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('production_cost_batches', 0);
    }

    public function test_retries_versions_and_other_components_are_isolated(): void
    {
        $this->owner();
        $payload = $this->payload();
        $id = $this->postJson(self::URL, $payload)->assertCreated()->json('id');
        $this->postJson(self::URL, array_replace($payload, ['length_m' => '100.0000']))->assertCreated()->assertJsonPath('id', $id);
        $this->postJson(self::URL, array_replace($payload, ['goods_uah' => '1100']))->assertConflict();
        $edit = array_replace($payload, ['version' => 1, 'allowance_cm' => 10]);
        unset($edit['request_key']);
        $this->putJson(self::URL.'/'.$id, $edit)->assertOk()->assertJsonPath('version', 2)->assertJsonPath('calculation.unit_cost_uah', 20.4);
        $this->putJson(self::URL.'/'.$id, $edit)->assertConflict();
        $this->putJson(self::URL.'/999999', $edit)->assertNotFound();
        $otherKey = (string) Str::uuid();
        $foam = app(ProductionCostService::class)->save(['request_key' => $otherKey, 'name' => 'Вставка', 'sheet_price_usd' => 4, 'usd_rate' => 40, 'sheet_length_cm' => 200, 'sheet_width_cm' => 100, 'blank_length_cm' => 20, 'blank_width_cm' => 10], null, null, 'foam');
        $this->putJson(self::URL.'/'.$foam['id'], $edit)->assertNotFound();
        $this->postJson(self::URL, $this->payload(['request_key' => $otherKey]))->assertConflict();
        $this->assertDatabaseHas('production_cost_batches', ['id' => $foam['id'], 'component' => 'foam', 'version' => 1, 'total_uah' => 160]);
        $this->assertDatabaseCount('production_cost_batch_revisions', 3);
        $this->assertDatabaseCount('sole_inventory_batches', 0);
    }

    public function test_private_import_is_idempotent_and_preserves_later_edits(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('tape.json', json_encode($this->payload()));
        $path = Storage::disk('local')->path('tape.json');
        $this->artisan('production-costs:import-tape', ['--file' => $path])->assertSuccessful();
        $this->artisan('production-costs:import-tape', ['--file' => $path])->assertSuccessful();
        $id = DB::table('production_cost_batches')->value('id');
        $this->owner();
        $edit = $this->payload(['version' => 1, 'shipping_uah' => 300]);
        unset($edit['request_key']);
        $this->putJson(self::URL.'/'.$id, $edit)->assertOk()->assertJsonPath('calculation.unit_cost_uah', 20.8);
        $this->artisan('production-costs:import-tape', ['--file' => $path])->assertSuccessful();
        $this->assertDatabaseHas('production_cost_batches', ['id' => $id, 'version' => 2, 'unit_cost_uah' => 20.8, 'user_id' => null]);
        Storage::disk('local')->put('tape.json', json_encode($this->payload(['length_m' => '0'])));
        $this->artisan('production-costs:import-tape', ['--file' => $path])->assertFailed();
        $this->assertDatabaseCount('production_cost_batches', 1);
        $this->assertDatabaseCount('production_cost_batch_revisions', 2);
    }

    public function test_history_is_paginated_and_a_new_batch_does_not_overwrite_the_old_one(): void
    {
        $this->owner();
        for ($i = 1; $i <= 21; $i++) {
            $this->postJson(self::URL, $this->payload(['name' => 'Стрічка '.$i]))->assertCreated();
        }
        $this->getJson(self::URL)->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('data.0.name', 'Стрічка 21');
        $this->getJson(self::URL.'?page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 21);
        $this->assertDatabaseCount('production_cost_batch_revisions', 21);
    }
}
