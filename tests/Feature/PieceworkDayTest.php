<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PieceworkDayTest extends TestCase
{
    use RefreshDatabase;

    private function employee(string $type = 'piecework'): int
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));

        return $this->postJson('/api/work-time/employees', ['request_key' => (string) Str::uuid(), 'name' => 'Тестовий виконавець', 'payment_type' => $type])->assertCreated()->json('id');
    }

    private function save(int $id, array $replace = [])
    {
        return $this->putJson("/api/work-time/employees/$id/piecework-day", array_replace(['date' => '2026-09-09', 'amount' => '600', 'version' => 0], $replace));
    }

    public function test_two_dates_store_two_amounts_without_hours_or_payments(): void
    {
        $id = $this->employee();
        $this->save($id)->assertOk()->assertJsonPath('amount', '600.00')->assertJsonPath('version', 1);
        $this->save($id, ['date' => '2026-09-20', 'amount' => '300'])->assertOk();
        $response = $this->getJson('/api/work-time/piecework-days?month=2026-09')->assertOk()->assertJsonCount(2, 'entries');
        $this->assertEquals(900, array_sum(array_column($response->json('entries'), 'amount')));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->getJson('/api/work-time/piecework-days?month=2026-10')->assertOk()->assertJsonCount(0, 'entries');
        $this->assertDatabaseCount('work_time_entries', 0);
        $this->assertDatabaseCount('work_payroll_months', 0);
        $this->assertDatabaseCount('work_piecework_entries', 0);
    }

    public function test_retries_versions_clearing_and_zero_preserve_history(): void
    {
        $id = $this->employee();
        $this->save($id, ['amount' => '600,25'])->assertOk()->assertJsonPath('amount', '600.25');
        $this->save($id, ['amount' => '600,25'])->assertOk()->assertJsonPath('version', 1);
        $this->save($id, ['amount' => '650'])->assertConflict();
        $this->save($id, ['amount' => '0', 'version' => 1])->assertOk()->assertJsonPath('amount', '0.00');
        $this->save($id, ['amount' => null, 'version' => 2])->assertOk()->assertJsonPath('amount', null)->assertJsonPath('version', 3);
        $this->save($id)->assertConflict();
        $this->assertDatabaseCount('work_piecework_days', 1);
        $this->assertDatabaseCount('work_time_revisions', 4);
    }

    public function test_access_is_owner_only_and_general_timesheet_never_leaks_amounts(): void
    {
        $id = $this->employee();
        $this->save($id)->assertOk();
        foreach (['operator', 'packer'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/work-time/piecework-days?month=2026-09')->assertForbidden();
            $this->save($id)->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'operator']));
        $this->getJson('/api/work-time?month=2026-09')->assertOk()->assertDontSee('600')->assertDontSee('amount');
        $this->actingAs(User::factory()->create(['role' => 'owner', 'is_active' => false]));
        $this->save($id)->assertForbidden();
    }

    public function test_validation_and_archival_preserve_past_months(): void
    {
        $id = $this->employee();
        foreach (['-1', '1e3', '12.333', '1000000001'] as $amount) {
            $this->save($id, ['amount' => $amount])->assertUnprocessable();
        }
        $this->save($id, ['date' => '2026-02-30'])->assertUnprocessable();
        $this->save($id, ['paid' => '600'])->assertUnprocessable();
        $this->save($id, ['date' => '2028-02-29'])->assertOk();
        $this->save(999999)->assertNotFound();
        $hourly = $this->employee('hourly');
        $this->save($hourly)->assertUnprocessable();
        DB::table('work_employees')->where('id', $id)->update(['archived_on' => '2026-08-31']);
        $this->save($id)->assertUnprocessable();
        $this->getJson('/api/work-time/piecework-days?month=2028-02')->assertOk()->assertJsonCount(1, 'employees');
        $this->getJson('/api/work-time/piecework-days?month=2026-09')->assertOk()->assertJsonCount(0, 'employees');
        $this->getJson('/api/work-time/piecework-days?month=bad')->assertUnprocessable();
    }

    public function test_previous_jobs_are_aggregated_without_duplication_or_deletion(): void
    {
        $id = $this->employee();
        $payload = ['request_key' => (string) Str::uuid(), 'employee_id' => $id, 'date' => '2026-09-09', 'description' => 'Попередня робота',
            'quantity' => 100, 'unit' => 'piece', 'pricing_mode' => 'fixed', 'agreed_total' => '600', 'paid' => '200'];
        $first = $this->postJson('/api/work-time/piecework', $payload)->assertCreated()->json('id');
        $this->postJson('/api/work-time/piecework', array_replace($payload, ['request_key' => (string) Str::uuid(), 'agreed_total' => '300']))->assertCreated();
        $this->getJson('/api/work-time/piecework-days?month=2026-09')->assertOk()->assertJsonCount(1, 'entries')
            ->assertJsonPath('entries.0.amount', '900.00')->assertJsonPath('entries.0.version', 2);
        $this->assertDatabaseCount('work_piecework_days', 0);
        $this->save($id)->assertConflict();
        $this->save($id, ['amount' => '950', 'version' => 2])->assertOk()->assertJsonPath('version', 3);
        $this->getJson('/api/work-time/piecework-days?month=2026-09')->assertOk()->assertJsonCount(1, 'entries')->assertJsonPath('entries.0.amount', '950.00');
        $this->assertDatabaseHas('work_piecework_entries', ['id' => $first, 'total_cents' => 60000, 'paid_cents' => 20000]);
        $this->postJson('/api/work-time/piecework', array_replace($payload, ['request_key' => (string) Str::uuid()]))->assertConflict();
        $this->putJson("/api/work-time/piecework/$first", $payload + ['version' => 1])->assertConflict();
        $this->putJson("/api/work-time/piecework/$first", array_replace($payload, ['version' => 1, 'date' => '2026-09-10']))->assertConflict();
        $this->save($id, ['amount' => null, 'version' => 3])->assertOk();
        $this->getJson('/api/work-time/piecework-days?month=2026-09')->assertOk()->assertJsonPath('entries.0.amount', null);
        $this->assertDatabaseCount('work_piecework_entries', 2);
    }

    public function test_moving_old_work_between_dates_does_not_reuse_a_stale_day_version(): void
    {
        $id = $this->employee();
        $payload = ['request_key' => (string) Str::uuid(), 'employee_id' => $id, 'date' => '2026-09-09', 'description' => 'Попередня робота',
            'quantity' => 1, 'unit' => 'piece', 'pricing_mode' => 'fixed', 'agreed_total' => '100', 'paid' => '0'];
        $first = $this->postJson('/api/work-time/piecework', $payload)->assertCreated()->json('id');
        $this->postJson('/api/work-time/piecework', array_replace($payload, ['request_key' => (string) Str::uuid()]))->assertCreated();
        $this->getJson('/api/work-time/piecework-days?month=2026-09')->assertOk()->assertJsonPath('entries.0.version', 2);
        $this->putJson("/api/work-time/piecework/$first", array_replace($payload, ['date' => '2026-09-10', 'version' => 1]))->assertOk();
        $this->postJson('/api/work-time/piecework', array_replace($payload, ['request_key' => (string) Str::uuid(), 'agreed_total' => '300']))->assertCreated();
        $this->save($id, ['amount' => '250', 'version' => 2])->assertConflict();
        $this->getJson('/api/work-time/piecework-days?month=2026-09')->assertOk()->assertJsonPath('entries.0.version', 4);
    }
}
