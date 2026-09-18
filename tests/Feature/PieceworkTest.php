<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PieceworkTest extends TestCase
{
    use RefreshDatabase;

    private function employee(string $type = 'piecework'): int
    {
        return $this->postJson('/api/work-time/employees', ['request_key' => (string) Str::uuid(), 'name' => 'Тестовий виконавець', 'payment_type' => $type])
            ->assertCreated()->assertJsonPath('payment_type', $type)->json('id');
    }

    private function payload(int $employee, array $replace = []): array
    {
        return array_replace(['request_key' => (string) Str::uuid(), 'employee_id' => $employee,
            'date' => '2026-09-18', 'description' => 'Тестовий розкрій', 'quantity' => 100, 'unit' => 'piece',
            'pricing_mode' => 'unit', 'unit_rate' => '2,35', 'paid' => '50', 'note' => null], $replace);
    }

    public function test_access_does_not_expose_piecework_finances_to_operators(): void
    {
        $this->getJson('/api/work-time/piecework?month=2026-09')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $id = $this->employee();
        $data = $this->payload($id);
        $entry = $this->postJson('/api/work-time/piecework', $data)->assertCreated()->json('id');
        foreach (['operator', 'packer'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/work-time/piecework?month=2026-09')->assertForbidden();
            $this->postJson('/api/work-time/piecework', $data)->assertForbidden();
            $this->putJson("/api/work-time/piecework/$entry", $data + ['version' => 1])->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'operator']));
        $this->getJson('/api/work-time?month=2026-09')->assertOk()->assertDontSee('unit_rate')->assertDontSee('paid')->assertDontSee('Тестовий розкрій');
        $this->actingAs(User::factory()->create(['role' => 'owner', 'is_active' => false]));
        $this->getJson('/api/work-time/piecework?month=2026-09')->assertForbidden();
    }

    public function test_unit_and_fixed_prices_are_calculated_in_cents_and_audited(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $employee = $this->employee();
        $payload = $this->payload($employee);
        $id = $this->postJson('/api/work-time/piecework', $payload)->assertCreated()->assertJsonPath('total', '235.00')->assertJsonPath('balance', '185.00')->json('id');
        $this->postJson('/api/work-time/piecework', $payload)->assertCreated()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('work_piecework_entries', 1);
        $this->assertDatabaseCount('work_time_revisions', 2);
        $this->postJson('/api/work-time/piecework', array_replace($payload, ['paid' => '60']))->assertConflict();
        $fixed = array_replace($payload, ['pricing_mode' => 'fixed', 'agreed_total' => '400,01', 'version' => 1, 'paid' => '450']);
        $this->putJson("/api/work-time/piecework/$id", $fixed)->assertOk()->assertJsonPath('total', '400.01')->assertJsonPath('unit_rate', null)->assertJsonPath('balance', '-49.99')->assertJsonPath('version', 2);
        $this->putJson("/api/work-time/piecework/$id", $fixed)->assertOk()->assertJsonPath('version', 2);
        $this->putJson("/api/work-time/piecework/$id", array_replace($fixed, ['paid' => '0']))->assertConflict();
        $this->assertDatabaseCount('work_time_revisions', 3);
        $this->assertDatabaseCount('work_time_entries', 0);
        $this->assertDatabaseCount('work_payroll_months', 0);
    }

    public function test_types_cannot_be_mixed_or_silently_changed(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $piece = $this->employee();
        $hourly = $this->employee('hourly');
        $this->postJson('/api/work-time/piecework', $this->payload($hourly))->assertUnprocessable();
        $this->putJson("/api/work-time/employees/$piece/entry", ['date' => '2026-09-18', 'hours' => '8', 'version' => 0])->assertUnprocessable();
        $this->getJson("/api/work-time/employees/$piece/payroll?month=2026-09")->assertUnprocessable();
        $this->putJson("/api/work-time/employees/$piece/payroll", ['month' => '2026-09', 'hourly_rate' => '10', 'bonus' => '0', 'paid' => '0', 'version' => 0])->assertUnprocessable();
        $this->putJson("/api/work-time/employees/$piece", ['name' => 'Тест', 'payment_type' => 'hourly', 'archived' => false, 'version' => 1])->assertUnprocessable();
        $key = (string) Str::uuid();
        $this->postJson('/api/work-time/employees', ['request_key' => $key, 'name' => 'Тест', 'payment_type' => 'piecework'])->assertCreated();
        $this->postJson('/api/work-time/employees', ['request_key' => $key, 'name' => 'Тест', 'payment_type' => 'hourly'])->assertConflict();
    }

    public function test_month_pagination_and_totals_include_all_pages_and_archived_history(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $employee = $this->employee();
        for ($i = 0; $i < 31; $i++) {
            $this->postJson('/api/work-time/piecework', $this->payload($employee))->assertCreated();
        }
        $this->postJson('/api/work-time/piecework', $this->payload($employee, ['date' => '2026-10-01']))->assertCreated();
        $this->getJson('/api/work-time/piecework?month=2026-09')->assertOk()->assertJsonCount(30, 'entries')->assertJsonPath('last_page', 2)
            ->assertJsonPath('count', 31)->assertJsonPath('summary.total', '7285.00')->assertJsonPath('summary.paid', '1550.00')->assertJsonPath('summary.balance', '5735.00');
        $this->getJson('/api/work-time/piecework?month=2026-09&page=2')->assertOk()->assertJsonCount(1, 'entries');
        DB::table('work_employees')->where('id', $employee)->update(['archived_on' => '2026-08-01']);
        $this->getJson('/api/work-time/piecework?month=2026-09')->assertOk()->assertJsonCount(1, 'employees');
        $this->getJson('/api/work-time/piecework?month=2026-11')->assertOk()->assertJsonCount(0, 'employees')->assertJsonPath('summary.total', '0.00');
        $this->postJson('/api/work-time/piecework', $this->payload($employee))->assertUnprocessable();
    }

    public function test_validation_rejects_invalid_or_forged_values(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $employee = $this->employee();
        foreach ([['quantity' => 0], ['quantity' => 1.5], ['unit_rate' => '-1'], ['unit_rate' => '2.333'], ['unit_rate' => '1e2'],
            ['paid' => '-1'], ['date' => '2026-02-30'], ['quantity' => 1000000, 'unit_rate' => '2'], ['unit' => 'hours'],
            ['pricing_mode' => 'fixed', 'agreed_total' => null], ['description' => ''], ['total' => '1'], ['balance' => '0']] as $values) {
            $this->postJson('/api/work-time/piecework', $this->payload($employee, $values))->assertUnprocessable();
        }
        $this->postJson('/api/work-time/piecework', $this->payload(999999))->assertNotFound();
        $this->putJson('/api/work-time/piecework/999999', $this->payload($employee, ['version' => 1]))->assertNotFound();
        $this->getJson('/api/work-time/piecework?month=invalid')->assertUnprocessable();
        $this->getJson('/api/work-time/piecework?month=2026-09&page=0')->assertUnprocessable();
        $this->assertDatabaseCount('work_piecework_entries', 0);
    }
}
