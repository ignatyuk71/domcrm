<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkEmployeeDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function employee(string $type = 'hourly'): int
    {
        return $this->postJson('/api/work-time/employees', ['name' => 'Тест видалення',
            'request_key' => (string) Str::uuid(), 'payment_type' => $type])->assertCreated()->json('id');
    }

    public function test_only_owner_can_delete_and_explicit_confirmation_and_current_version_are_required(): void
    {
        $this->deleteJson('/api/work-time/employees/1')->assertUnauthorized();
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner);
        $id = $this->employee();
        $url = "/api/work-time/employees/$id";
        foreach (['operator', 'packer'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->deleteJson($url, ['version' => 1, 'confirmed' => true])->assertForbidden();
        }
        $this->actingAs($owner);
        $this->deleteJson($url, ['version' => 1])->assertUnprocessable();
        $this->deleteJson($url, ['version' => 1, 'confirmed' => false])->assertUnprocessable();
        $this->deleteJson($url, ['version' => 2, 'confirmed' => true])->assertConflict();
        $this->assertDatabaseHas('work_employees', ['id' => $id]);
        $owner->update(['is_active' => false]);
        $this->deleteJson($url, ['version' => 1, 'confirmed' => true])->assertForbidden();
    }

    public function test_hourly_deletion_removes_all_months_and_private_revisions_but_preserves_other_employee(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $id = $this->employee();
        $other = $this->employee();
        foreach ([$id, $other] as $employee) {
            foreach (['2026-08', '2026-09'] as $month) {
                $this->putJson("/api/work-time/employees/$employee/entry", ['date' => "$month-01", 'hours' => '8', 'version' => 0])->assertOk();
                $this->putJson("/api/work-time/employees/$employee/payroll", ['month' => $month, 'hourly_rate' => '50', 'bonus' => '100', 'paid' => '200', 'version' => 0])->assertOk();
            }
        }
        $otherRevisions = DB::table('work_time_revisions')->where('id', '>', 6)->get()->toJson();
        $payload = ['version' => 1, 'confirmed' => true];
        $this->deleteJson("/api/work-time/employees/$id", $payload)->assertOk()->assertJsonPath('deleted', true);
        $this->deleteJson("/api/work-time/employees/$id", $payload)->assertOk();
        foreach (['work_employees' => 'id', 'work_time_entries' => 'employee_id', 'work_payroll_months' => 'employee_id'] as $table => $column) {
            $this->assertDatabaseMissing($table, [$column => $id]);
            $this->assertDatabaseHas($table, [$column => $other]);
        }
        $this->assertSame($otherRevisions, DB::table('work_time_revisions')->where('id', '>', 6)->where('subject_type', '!=', 'employee_deleted')->get()->toJson());
        $this->assertDatabaseCount('work_time_revisions', 6);
        $audit = DB::table('work_time_revisions')->where('subject_type', 'employee_deleted')->sole();
        $this->assertNull($audit->before);
        $this->assertSame(['deleted' => true], json_decode($audit->after, true));
        $this->putJson("/api/work-time/employees/$id/entry", ['date' => '2026-09-01', 'hours' => '9', 'version' => 1])->assertNotFound();
    }

    public function test_piecework_deletion_removes_legacy_and_daily_amounts_and_their_revisions(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $id = $this->employee('piecework');
        $this->postJson('/api/work-time/piecework', ['employee_id' => $id, 'request_key' => (string) Str::uuid(),
            'date' => '2026-08-01', 'description' => 'Розкрій', 'quantity' => 100, 'unit' => 'piece',
            'pricing_mode' => 'unit', 'unit_rate' => '2', 'paid' => '50'])->assertCreated();
        $this->putJson("/api/work-time/employees/$id/piecework-day", ['date' => '2026-09-01', 'amount' => '600', 'version' => 0])->assertOk();
        $this->deleteJson("/api/work-time/employees/$id", ['version' => 1, 'confirmed' => true])->assertOk();
        foreach (['work_employees', 'work_piecework_entries', 'work_piecework_days'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('work_time_revisions', 1);
        $this->getJson('/api/work-time/piecework-days?month=2026-09')->assertOk()->assertJsonPath('employees', [])->assertJsonPath('entries', []);
        $this->putJson("/api/work-time/employees/$id/piecework-day", ['date' => '2026-09-01', 'amount' => '700', 'version' => 1])->assertNotFound();
    }
}
