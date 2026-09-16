<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Services\Costs\SoleCostCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionCostsTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $replace = []): array
    {
        return array_replace([
            'request_key' => (string) Str::uuid(), 'name' => 'Тестова партія', 'purchased_on' => null,
            'quantity' => 100, 'goods_cny' => '200', 'china_shipping_cny' => '10', 'commission_percent' => '10',
            'international_shipping_usd' => '100', 'ukraine_shipping_uah' => '500', 'other_costs_uah' => '0',
            'cny_rate' => '6', 'usd_rate' => '40', 'note' => null,
        ], $replace);
    }

    private function owner(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    }

    private function importFile(): string
    {
        Storage::fake('local');
        Storage::disk('local')->put('cost-fixture.json', json_encode($this->payload(), JSON_THROW_ON_ERROR));

        return Storage::disk('local')->path('cost-fixture.json');
    }

    public function test_only_owner_can_open_read_or_write_costs(): void
    {
        $this->get('/analytics/costs')->assertRedirect(route('login'));
        $this->getJson('/api/production-costs/sole-batches')->assertUnauthorized();
        $this->postJson('/api/production-costs/sole-batches', $this->payload())->assertUnauthorized();
        foreach ([User::ROLE_OPERATOR, User::ROLE_PACKER] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get('/analytics/costs')->assertForbidden();
            $this->getJson('/api/production-costs/sole-batches')->assertForbidden();
            $this->postJson('/api/production-costs/sole-batches', $this->payload())->assertForbidden();
            $this->putJson('/api/production-costs/sole-batches/1', [])->assertForbidden();
        }
        $this->owner();
        $this->get('/analytics/costs')->assertOk()->assertSee('crm-production-costs')->assertSessionHas('analytics.last_tab', 'costs');
    }

    public function test_get_is_read_only_and_has_no_invented_or_automatically_seeded_values(): void
    {
        $this->owner();
        $this->getJson('/api/production-costs/sole-batches')->assertOk()->assertJsonPath('data', [])->assertJsonPath('total', 0);
        $this->assertDatabaseCount('production_cost_batches', 0);
        $this->assertDatabaseCount('production_cost_batch_revisions', 0);
        $this->assertDatabaseCount('sole_inventory_batches', 0);
        $this->getJson('/api/production-costs/sole-batches?page=-1')->assertUnprocessable();
    }

    public function test_private_batch_is_imported_once_and_has_correct_cost_without_inventory_writes(): void
    {
        $file = $this->importFile();
        $this->artisan('production-costs:import-soles', ['--file' => $file])->assertSuccessful();
        $this->artisan('production-costs:import-soles', ['--file' => $file])->assertSuccessful();
        $this->owner();
        $this->getJson('/api/production-costs/sole-batches')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.quantity', 100)
            ->assertJsonPath('data.0.purchased_on', null)
            ->assertJsonPath('data.0.inputs.goods_cny', '200.00')
            ->assertJsonPath('data.0.inputs.cny_rate', '6.0000')
            ->assertJsonPath('data.0.calculation.commission_cny', 21)
            ->assertJsonPath('data.0.calculation.total_cny', 231)
            ->assertJsonPath('data.0.calculation.total_uah', 5886)
            ->assertJsonPath('data.0.calculation.unit_cost_uah', 58.86)
            ->assertJsonPath('data.0.calculation.breakdown.3.total_uah', 4000);
        $this->assertDatabaseCount('production_cost_batch_revisions', 1);
        $this->assertDatabaseCount('sole_inventory_batches', 0);
        $this->assertDatabaseCount('sole_inventory_plans', 0);
    }

    public function test_server_recalculates_localized_inputs_and_rejects_submitted_totals(): void
    {
        $this->owner();
        $payload = $this->payload(['cny_rate' => '6,00', 'usd_rate' => '40,00', 'international_shipping_usd' => '100,00']);
        $this->postJson('/api/production-costs/sole-batches', $payload + ['total_uah' => 1])->assertUnprocessable()->assertJsonValidationErrors('total_uah');
        $this->postJson('/api/production-costs/sole-batches', $payload)->assertCreated()
            ->assertJsonPath('calculation.total_uah', 5886)->assertJsonPath('version', 1);
    }

    public function test_import_rejects_invalid_private_files_without_creating_records(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('invalid.json', '{bad json}');
        $path = Storage::disk('local')->path('invalid.json');
        $this->artisan('production-costs:import-soles', ['--file' => $path])->assertFailed();
        Storage::disk('local')->put('invalid.json', json_encode($this->payload(['quantity' => 0])));
        $this->artisan('production-costs:import-soles', ['--file' => $path])->assertFailed();
        $this->artisan('production-costs:import-soles')->assertFailed();
        $this->assertDatabaseCount('production_cost_batches', 0);
    }

    public function test_new_batch_keeps_old_amounts_and_rates_and_does_not_change_stock(): void
    {
        $this->owner();
        $first = $this->postJson('/api/production-costs/sole-batches', $this->payload())->assertCreated()->json('id');
        $second = $this->postJson('/api/production-costs/sole-batches', $this->payload(['name' => 'Нова партія', 'quantity' => 50, 'usd_rate' => '41', 'other_costs_uah' => '100']))->assertCreated();
        $second->assertJsonPath('calculation.total_uah', 6086)->assertJsonPath('calculation.unit_cost_uah', 121.72);
        $this->assertDatabaseHas('production_cost_batches', ['id' => $first, 'total_uah' => 5886]);
        $this->assertDatabaseCount('production_cost_batches', 2);
        $this->assertDatabaseCount('sole_inventory_batches', 0);
    }

    public function test_editing_is_versioned_audited_and_never_overwritten_by_import(): void
    {
        $file = $this->importFile();
        $this->artisan('production-costs:import-soles', ['--file' => $file])->assertSuccessful();
        $id = DB::table('production_cost_batches')->value('id');
        $this->owner();
        $payload = $this->payload(['version' => 1, 'usd_rate' => '41']);
        unset($payload['request_key']);
        $this->putJson('/api/production-costs/sole-batches/'.$id, $payload)->assertOk()->assertJsonPath('version', 2)
            ->assertJsonPath('calculation.total_uah', 5986);
        $this->putJson('/api/production-costs/sole-batches/'.$id, $payload)->assertConflict();
        $this->putJson('/api/production-costs/sole-batches/999999', $payload)->assertNotFound();
        $this->artisan('production-costs:import-soles', ['--file' => $file])->assertSuccessful();
        $this->assertDatabaseHas('production_cost_batches', ['id' => $id, 'version' => 2, 'total_uah' => 5986]);
        $this->assertDatabaseCount('production_cost_batch_revisions', 2);
    }

    public function test_retried_creation_is_idempotent_but_changed_payload_conflicts(): void
    {
        $this->owner();
        $payload = $this->payload();
        $id = $this->postJson('/api/production-costs/sole-batches', $payload)->assertCreated()->json('id');
        $this->postJson('/api/production-costs/sole-batches', array_replace($payload, ['cny_rate' => '6.0000']))->assertCreated()->assertJsonPath('id', $id);
        $this->postJson('/api/production-costs/sole-batches', array_replace($payload, ['quantity' => 2000]))->assertConflict();
        $this->assertDatabaseCount('production_cost_batches', 1);
        $this->assertDatabaseCount('production_cost_batch_revisions', 1);
    }

    public function test_invalid_quantities_amounts_and_rates_cannot_be_saved(): void
    {
        $this->owner();
        foreach ([['quantity', 0], ['quantity', 1.5], ['goods_cny', -1], ['goods_cny', '1.001'], ['goods_cny', '1e4'],
            ['usd_rate', 0], ['usd_rate', '45.12345'], ['cny_rate', 1001], ['commission_percent', 101],
            ['international_shipping_usd', 1000001], ['purchased_on', '2026-02-30'], ['name', '']] as [$field, $value]) {
            $this->postJson('/api/production-costs/sole-batches', $this->payload([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('production_cost_batches', 0);
    }

    public function test_decimal_rounding_uses_minor_currency_units_and_zero_commission_is_supported(): void
    {
        $calculator = app(SoleCostCalculator::class);
        $inputs = $calculator->normalize($this->payload(['goods_cny' => '0.05', 'china_shipping_cny' => '0', 'commission_percent' => '10',
            'international_shipping_usd' => '0', 'ukraine_shipping_uah' => '0', 'other_costs_uah' => '0', 'cny_rate' => '7.1234']));
        $result = $calculator->calculate(3, $inputs);
        $this->assertSame(0.01, $result['commission_cny']);
        $this->assertSame(0.43, $result['total_uah']);
        $this->assertSame(0.143333, $result['unit_cost_uah']);
        $inputs['commission_percent'] = '0.00';
        $this->assertEquals(0, $calculator->calculate(3, $inputs)['commission_cny']);
    }

    public function test_history_is_paginated_and_shows_latest_saved_batches_first(): void
    {
        $this->owner();
        for ($i = 1; $i <= 22; $i++) {
            $this->postJson('/api/production-costs/sole-batches', $this->payload(['name' => 'Партія '.$i]))->assertCreated();
        }
        $this->getJson('/api/production-costs/sole-batches')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('data.0.name', 'Партія 22')->assertJsonPath('last_page', 2);
        $this->getJson('/api/production-costs/sole-batches?page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('total', 22);
    }
}
