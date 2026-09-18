<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkTimeTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $role = 'owner'): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user);

        return $user;
    }

    private function employee(): int
    {
        return $this->postJson('/api/work-time/employees', ['request_key' => (string) Str::uuid(), 'name' => 'Тестовий працівник', 'position' => 'Тестова посада'])
            ->assertCreated()->json('id');
    }

    private function day(int $id, array $replace = [])
    {
        return $this->putJson("/api/work-time/employees/$id/entry", array_replace(['date' => '2026-09-01', 'hours' => '8', 'note' => null, 'version' => 0], $replace));
    }

    private function pay(int $id, array $replace = [])
    {
        return $this->putJson("/api/work-time/employees/$id/payroll", array_replace(['month' => '2026-09', 'hourly_rate' => '50', 'bonus' => '100', 'paid' => '200', 'version' => 0], $replace));
    }

    public function test_access_and_financial_data_are_separated_server_side(): void
    {
        $this->getJson('/api/work-time?month=2026-09')->assertUnauthorized();
        $this->login();
        $id = $this->employee();
        $this->pay($id)->assertOk();
        $this->get('/work-time')->assertOk()->assertSee('crm-work-time');
        $this->login('operator');
        $this->get('/work-time')->assertOk()->assertSee('data-can-manage-pay="0"', false);
        $this->getJson('/api/work-time?month=2026-09')->assertOk()->assertJsonPath('can_manage_pay', false)
            ->assertJsonMissingPath('employees.0.hourly_rate')->assertDontSee('bonus')->assertDontSee('paid');
        $this->day($id)->assertOk();
        $this->getJson("/api/work-time/employees/$id/payroll?month=2026-09")->assertForbidden();
        $this->pay($id)->assertForbidden();
        $this->postJson('/api/work-time/employees', [])->assertForbidden();
        $this->putJson("/api/work-time/employees/$id", [])->assertForbidden();
        $this->login('packer');
        $this->get('/work-time')->assertForbidden();
        $this->getJson('/api/work-time?month=2026-09')->assertForbidden();
        $this->day($id)->assertForbidden();
        $this->login('owner')->update(['is_active' => false]);
        $this->getJson('/api/work-time?month=2026-09')->assertForbidden();
    }

    public function test_empty_month_is_read_only_and_never_seeds_private_data(): void
    {
        $this->login();
        $this->getJson('/api/work-time?month=2026-09')->assertOk()->assertJsonPath('employees', [])->assertJsonPath('entries', []);
        foreach (['work_employees', 'work_time_entries', 'work_payroll_months', 'work_time_revisions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_employee_creation_retries_are_idempotent(): void
    {
        $this->login();
        $payload = ['request_key' => (string) Str::uuid(), 'name' => 'Тест'];
        $id = $this->postJson('/api/work-time/employees', $payload)->assertCreated()->json('id');
        $this->postJson('/api/work-time/employees', $payload)->assertCreated()->assertJsonPath('id', $id);
        $this->postJson('/api/work-time/employees', array_replace($payload, ['name' => 'Інше']))->assertConflict();
        $this->assertDatabaseCount('work_employees', 1);
        $this->assertDatabaseCount('work_time_revisions', 1);
    }

    public function test_hours_are_localized_versioned_audited_and_month_scoped(): void
    {
        $actor = $this->login();
        $id = $this->employee();
        $this->day($id, ['hours' => '7,5'])->assertOk()->assertJsonPath('hours', '7.50')->assertJsonPath('version', 1)->assertJsonPath('updated_by', $actor->name);
        $this->day($id, ['hours' => '7,5'])->assertOk()->assertJsonPath('version', 1);
        $this->day($id, ['hours' => '6'])->assertConflict();
        $this->day($id, ['hours' => '6', 'version' => 1])->assertOk()->assertJsonPath('version', 2);
        $this->getJson('/api/work-time?month=2026-09')->assertOk()->assertJsonCount(1, 'entries')->assertJsonPath('entries.0.hours', '6.00');
        $this->getJson('/api/work-time?month=2026-10')->assertOk()->assertJsonPath('entries', []);
        $this->assertDatabaseCount('work_time_revisions', 3);
        $this->assertDatabaseHas('work_time_entries', ['employee_id' => $id, 'hour_units' => 600, 'updated_by' => $actor->id]);
    }

    public function test_blank_and_explicit_zero_are_distinct_and_clearing_keeps_version(): void
    {
        $this->login();
        $id = $this->employee();
        $this->day($id, ['hours' => '0'])->assertOk()->assertJsonPath('hours', '0.00');
        $this->day($id, ['hours' => null, 'version' => 1])->assertOk()->assertJsonPath('hours', null)->assertJsonPath('version', 2);
        $this->day($id, ['version' => 0])->assertConflict();
        $this->assertDatabaseCount('work_time_entries', 1);
    }

    public function test_validation_rejects_invalid_dates_hours_money_and_injected_totals(): void
    {
        $this->login();
        $id = $this->employee();
        foreach (['25', '-1', '8.001', 'NaN', '1e1'] as $hours) {
            $this->day($id, ['hours' => $hours])->assertUnprocessable();
        }
        foreach (['2026-02-30', '2026-13-01', '1999-01-01'] as $date) {
            $this->day($id, ['date' => $date])->assertUnprocessable();
        }
        $this->day($id, ['date' => '2028-02-29', 'hours' => '24'])->assertOk();
        $this->day(99999)->assertNotFound();
        $this->pay($id, ['hourly_rate' => '-1'])->assertUnprocessable();
        $this->pay($id, ['bonus' => '1000001'])->assertUnprocessable();
        $this->pay($id, ['paid' => '1.999'])->assertUnprocessable();
        $this->pay($id, ['balance' => 1])->assertUnprocessable();
        foreach (['2026-13', '2026-9', '2026-09-01', '1999-01'] as $month) {
            $this->getJson('/api/work-time?month='.$month)->assertUnprocessable();
        }
        $this->assertDatabaseCount('work_payroll_months', 0);
    }

    public function test_payroll_uses_saved_hours_and_rate_is_independent_for_each_month(): void
    {
        $this->login();
        $id = $this->employee();
        $this->day($id, ['hours' => '7.5'])->assertOk();
        $this->getJson("/api/work-time/employees/$id/payroll?month=2026-09")->assertOk()->assertJsonPath('hourly_rate', null)->assertJsonPath('balance', null);
        $this->assertDatabaseCount('work_payroll_months', 0);
        $this->pay($id, ['hourly_rate' => '50,10'])->assertOk()->assertJsonPath('base_pay', '375.75')->assertJsonPath('accrued', '475.75')->assertJsonPath('balance', '275.75')->assertJsonPath('version', 1);
        $this->pay($id, ['hourly_rate' => '50,10'])->assertOk()->assertJsonPath('version', 1);
        $this->pay($id, ['hourly_rate' => '60'])->assertConflict();
        $this->pay($id, ['month' => '2026-10', 'hourly_rate' => '60'])->assertOk();
        $this->getJson("/api/work-time/employees/$id/payroll?month=2026-09")->assertOk()->assertJsonPath('hourly_rate', '50.10');
        $this->day($id, ['hours' => '8', 'version' => 1])->assertOk();
        $this->getJson("/api/work-time/employees/$id/payroll?month=2026-09")->assertOk()->assertJsonPath('base_pay', '400.80');
        $this->pay($id, ['hourly_rate' => null, 'version' => 1])->assertOk()->assertJsonPath('balance', null);
    }

    public function test_archiving_keeps_history_and_blocks_new_days_after_archive(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 18));
        $this->login();
        $id = $this->employee();
        $this->day($id)->assertOk();
        $payload = ['name' => 'Тестовий працівник', 'position' => 'Тестова посада', 'archived' => true, 'version' => 1];
        $this->putJson("/api/work-time/employees/$id", $payload)->assertOk()->assertJsonPath('archived_on', '2026-09-18');
        $this->day($id, ['date' => '2026-09-19'])->assertUnprocessable();
        $this->getJson('/api/work-time?month=2026-09')->assertOk()->assertJsonCount(1, 'employees');
        $this->getJson('/api/work-time?month=2026-10')->assertOk()->assertJsonCount(0, 'employees');
        $this->day($id, ['hours' => '7', 'version' => 1])->assertOk();
        $this->putJson("/api/work-time/employees/$id", array_replace($payload, ['archived' => false]))->assertConflict();
        $this->putJson("/api/work-time/employees/$id", array_replace($payload, ['archived' => false, 'version' => 2]))->assertOk();
        $this->assertDatabaseCount('work_time_entries', 1);
        $this->travelBack();
    }
}
