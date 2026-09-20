<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkPayrollSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner);

        return $owner;
    }

    private function employee(string $type = 'hourly'): int
    {
        return $this->postJson('/api/work-time/employees', ['request_key' => (string) Str::uuid(), 'name' => 'Тестова людина', 'payment_type' => $type])->assertCreated()->json('id');
    }

    private function payload(array $replace = []): array
    {
        return array_replace(['month' => '2026-09', 'rate_mode' => 'daily', 'rate' => '350', 'bonus' => '100', 'expenses' => '200',
            'adjustment' => '-20.50', 'adjustment_reason' => 'Тестове коригування', 'paid' => '300', 'note' => null, 'version' => 0], $replace);
    }

    public function test_page_directory_report_and_saves_are_owner_only(): void
    {
        $this->getJson('/settings/work-payroll/report?month=2026-09')->assertUnauthorized();
        $owner = $this->owner();
        $id = $this->employee();
        $this->get('/settings/work-payroll')->assertOk()->assertSee('crm-settings-work-payroll')->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/settings/work-payroll/employees')->assertOk()->assertJsonCount(1, 'employees');
        foreach (['operator', 'packer'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get('/settings/work-payroll')->assertForbidden();
            $this->getJson('/settings/work-payroll/employees')->assertForbidden();
            $this->getJson('/settings/work-payroll/report?month=2026-09')->assertForbidden();
            $this->putJson("/settings/work-payroll/employees/$id", $this->payload())->assertForbidden();
        }
        $this->actingAs($owner); $owner->update(['is_active' => false]);
        $this->getJson('/settings/work-payroll/report?month=2026-09')->assertForbidden();
    }

    public function test_daily_rate_is_proportional_to_hours_and_adjustments_expenses_and_paid_are_separate(): void
    {
        $this->owner(); $id = $this->employee();
        foreach (['01' => '8', '02' => '4'] as $day => $hours) {
            $this->putJson("/api/work-time/employees/$id/entry", ['date' => "2026-09-$day", 'hours' => $hours, 'version' => 0])->assertOk();
        }
        $this->putJson("/settings/work-payroll/employees/$id", $this->payload())->assertOk()->assertJsonPath('base_pay', '600.00')
            ->assertJsonPath('daily_hours', 7)->assertJsonPath('salary', '579.50')->assertJsonPath('accrued', '879.50')->assertJsonPath('balance', '579.50')->assertJsonPath('days', 2);
        $this->getJson('/settings/work-payroll/report?month=2026-09')->assertOk()->assertJsonPath('totals.accrued', '879.50')
            ->assertJsonPath('totals.balance', '579.50')->assertJsonPath('incomplete_count', 0);
        $this->getJson("/api/work-time/employees/$id/payroll?month=2026-09")->assertOk()->assertJsonPath('base_pay', '600.00');
        $this->putJson("/settings/work-payroll/employees/$id", $this->payload())->assertOk()->assertJsonPath('version', 1);
        $this->putJson("/settings/work-payroll/employees/$id", $this->payload(['bonus' => '101']))->assertConflict();
        $this->assertDatabaseCount('work_payroll_months', 1);
        $this->assertSame(1, DB::table('work_time_revisions')->where('subject_type', 'payroll')->count());
    }

    public function test_rates_do_not_leak_across_months_and_unset_is_not_zero_and_rounding_is_once(): void
    {
        $this->owner(); $id = $this->employee();
        $this->putJson("/api/work-time/employees/$id/entry", ['date' => '2026-09-01', 'hours' => '1.01', 'version' => 0])->assertOk();
        $this->getJson('/settings/work-payroll/report?month=2026-09')->assertOk()->assertJsonPath('rows.0.accrued', null)->assertJsonPath('incomplete_count', 1);
        $this->assertDatabaseCount('work_payroll_months', 0);
        $this->putJson("/settings/work-payroll/employees/$id", $this->payload(['rate_mode' => 'hourly', 'rate' => '1.50', 'bonus' => '0', 'expenses' => '0', 'adjustment' => '0', 'paid' => '5']))
            ->assertOk()->assertJsonPath('base_pay', '1.52')->assertJsonPath('balance', '-3.48');
        $this->getJson('/settings/work-payroll/report?month=2026-10')->assertOk()->assertJsonPath('rows.0.accrued', null);
        $this->putJson("/settings/work-payroll/employees/$id", $this->payload(['month' => '2026-10', 'rate' => '0']))->assertOk()->assertJsonPath('base_pay', '0.00');
        $this->getJson('/settings/work-payroll/report?month=2026-09')->assertOk()->assertJsonPath('rows.0.base_pay', '1.52');
    }

    public function test_seven_hour_day_pays_shorter_and_longer_days_without_rounding_hourly_rate(): void
    {
        $this->owner();
        foreach ([['350', '7', '350.00'], ['350', '9', '450.00'], ['400', '7', '400.00'],
            ['400', '8', '457.14'], ['400', '9', '514.29'], ['400', '3.5', '200.00'], ['400', '0', '0.00']] as [$rate, $hours, $expected]) {
            $id = $this->employee();
            $this->putJson("/api/work-time/employees/$id/entry", ['date' => '2026-09-01', 'hours' => $hours, 'version' => 0])->assertOk();
            $this->putJson("/settings/work-payroll/employees/$id", $this->payload(['rate' => $rate, 'bonus' => '0', 'expenses' => '0', 'adjustment' => '0', 'paid' => '0']))
                ->assertOk()->assertJsonPath('daily_hours', 7)->assertJsonPath('base_pay', $expected)->assertJsonPath('accrued', $expected);
            $this->assertDatabaseHas('work_payroll_months', ['employee_id' => $id, 'daily_hours' => 7]);
        }
    }

    public function test_historical_months_keep_eight_hours_and_existing_september_rates_use_seven(): void
    {
        $this->owner(); $id = $this->employee();
        foreach (['2026-08', '2026-09'] as $month) {
            $this->putJson("/api/work-time/employees/$id/entry", ['date' => "$month-01", 'hours' => '9', 'version' => 0])->assertOk();
            DB::table('work_payroll_months')->insert(['employee_id' => $id, 'month' => "$month-01", 'rate_mode' => 'daily', 'daily_rate_cents' => 40000,
                'daily_hours' => null, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->getJson('/settings/work-payroll/report?month=2026-08')->assertOk()->assertJsonPath('rows.0.daily_hours', 8)->assertJsonPath('rows.0.base_pay', '450.00');
        $this->getJson('/settings/work-payroll/report?month=2026-09')->assertOk()->assertJsonPath('rows.0.daily_hours', 7)->assertJsonPath('rows.0.base_pay', '514.29');
        $this->assertSame(2, DB::table('work_payroll_months')->whereNull('daily_hours')->count());
        $this->putJson("/settings/work-payroll/employees/$id", $this->payload(['month' => '2026-08', 'rate' => '400', 'version' => 1]))->assertOk()
            ->assertJsonPath('daily_hours', 8)->assertJsonPath('base_pay', '450.00');
        $this->assertDatabaseHas('work_payroll_months', ['employee_id' => $id, 'month' => '2026-08-01', 'daily_hours' => 8, 'daily_rate_cents' => 40000]);
        $this->getJson('/settings/work-payroll/report?month=2027-01')->assertOk()->assertJsonPath('rows.0.daily_hours', 7);
        $this->putJson("/settings/work-payroll/employees/$id", $this->payload(['daily_hours' => 1]))->assertUnprocessable()->assertJsonValidationErrors('daily_hours');
    }

    public function test_piecework_uses_daily_overrides_without_double_counting_and_preserves_legacy_payments(): void
    {
        $this->owner(); $id = $this->employee('piecework');
        $this->postJson('/api/work-time/piecework', ['employee_id' => $id, 'request_key' => (string) Str::uuid(), 'date' => '2026-09-01',
            'description' => 'Тест', 'quantity' => 100, 'unit' => 'piece', 'pricing_mode' => 'unit', 'unit_rate' => '2', 'paid' => '50'])->assertCreated();
        $this->putJson("/api/work-time/employees/$id/piecework-day", ['date' => '2026-09-01', 'amount' => '600', 'version' => 1])->assertOk();
        $this->putJson("/api/work-time/employees/$id/piecework-day", ['date' => '2026-09-20', 'amount' => '300', 'version' => 0])->assertOk();
        $this->getJson('/settings/work-payroll/report?month=2026-09')->assertOk()->assertJsonPath('rows.0.base_pay', '900.00')->assertJsonPath('rows.0.paid', '50.00');
        $this->putJson("/settings/work-payroll/employees/$id", $this->payload(['rate_mode' => 'piecework', 'rate' => null, 'bonus' => '0', 'expenses' => '0', 'adjustment' => '0', 'paid' => '500']))
            ->assertOk()->assertJsonPath('balance', '400.00');
        $this->putJson("/api/work-time/employees/$id/piecework-day", ['date' => '2026-09-01', 'amount' => null, 'version' => 2])->assertOk();
        $this->getJson('/settings/work-payroll/report?month=2026-09')->assertOk()->assertJsonPath('rows.0.base_pay', '300.00')->assertJsonPath('rows.0.paid', '500.00')->assertJsonPath('totals.balance', '-200.00');
    }

    public function test_validation_archived_directory_and_historical_reports(): void
    {
        $this->owner(); $id = $this->employee();
        foreach ([['rate' => '-1'], ['adjustment_reason' => ' '], ['bonus' => '1e3'], ['expenses' => '1.001'], ['rate_mode' => 'piecework'], ['base_pay' => '999'], ['month' => '2026-13']] as $invalid) {
            $this->putJson("/settings/work-payroll/employees/$id", $this->payload($invalid))->assertUnprocessable();
        }
        $this->putJson("/settings/work-payroll/employees/$id", $this->payload(['month' => '2025-01']))->assertOk();
        $this->putJson("/api/work-time/employees/$id", ['name' => 'Тестова людина', 'archived' => true, 'version' => 1])->assertOk();
        $this->getJson('/settings/work-payroll/employees')->assertOk()->assertJsonCount(1, 'employees');
        $this->getJson('/settings/work-payroll/report?month=2025-01')->assertOk()->assertJsonCount(1, 'rows');
        $this->getJson('/settings/work-payroll/report?month=2100-12')->assertOk()->assertJsonCount(0, 'rows');
        $this->deleteJson("/api/work-time/employees/$id", ['version' => 2, 'confirmed' => true])->assertOk();
        $this->assertDatabaseCount('work_payroll_months', 0);
    }
}
