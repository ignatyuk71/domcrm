<?php

namespace Tests\Feature\Orders;

use App\Models\Status;
use Database\Seeders\StatusesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderStatusAgeMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // DDL перевіряємо без транзакції: MySQL автоматично завершує її під час ALTER TABLE.
        $this->artisan('migrate:fresh')->assertSuccessful();
        $this->beforeApplicationDestroyed(function () {
            $this->artisan('db:wipe')->assertSuccessful();
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });
    }

    public function test_backfill_uses_only_the_latest_matching_event_and_preserves_other_order_data(): void
    {
        $this->seed(StatusesSeeder::class);
        $migration = require database_path('migrations/2026_09_08_000001_add_status_changed_at_to_orders_table.php');
        $migration->down();

        $confirmedId = (int) Status::where('type', 'order')->where('code', 'confirmed')->value('id');
        $shippedId = (int) Status::where('type', 'order')->where('code', 'shipped')->value('id');
        $orderIds = [];
        foreach (['no_history', 'matching', 'outdated', 'wrong_id', 'wrong_code', 'nullable_id'] as $case) {
            $orderIds[$case] = DB::table('orders')->insertGetId([
                'order_number' => 'MIG-'.$case,
                'status' => 'confirmed',
                'status_id' => $case === 'nullable_id' ? null : $confirmedId,
                'created_at' => '2026-01-01 10:00:00',
                'updated_at' => '2026-09-08 11:00:00',
            ]);
        }
        $addEvent = function (string $case, string $status, ?int $statusId, string $time) use ($orderIds): void {
            DB::table('order_status_changes')->insert([
                'order_id' => $orderIds[$case],
                'order_number' => 'MIG-'.$case,
                'new_status' => $status,
                'new_status_id' => $statusId,
                'source' => 'system',
                'reason' => 'Тест відновлення часу',
                'occurred_at' => $time,
            ]);
        };
        $addEvent('matching', 'confirmed', $confirmedId, '2026-09-07 12:00:00');
        $addEvent('matching', 'shipped', $shippedId, '2026-09-07 13:00:00');
        $addEvent('matching', 'confirmed', $confirmedId, '2026-09-07 14:00:00');
        $addEvent('outdated', 'confirmed', $confirmedId, '2026-09-07 12:00:00');
        $addEvent('outdated', 'shipped', $shippedId, '2026-09-07 13:00:00');
        $addEvent('wrong_id', 'confirmed', $shippedId, '2026-09-07 14:00:00');
        $addEvent('wrong_code', 'shipped', $confirmedId, '2026-09-07 14:00:00');
        $addEvent('nullable_id', 'confirmed', null, '2026-09-07 15:00:00');
        $before = DB::table('orders')->orderBy('id')->get()->map(fn ($order) => (array) $order)->all();
        $historyBefore = DB::table('order_status_changes')->orderBy('id')->get()->all();

        $migration->up();

        foreach (['no_history', 'outdated', 'wrong_id', 'wrong_code'] as $case) {
            $this->assertNull(DB::table('orders')->where('id', $orderIds[$case])->value('status_changed_at'), $case);
        }
        $this->assertSame('2026-09-07 14:00:00', DB::table('orders')->where('id', $orderIds['matching'])->value('status_changed_at'));
        $this->assertSame('2026-09-07 15:00:00', DB::table('orders')->where('id', $orderIds['nullable_id'])->value('status_changed_at'));
        $after = DB::table('orders')->orderBy('id')->get()->map(function ($order) {
            $attributes = (array) $order;
            unset($attributes['status_changed_at']);

            return $attributes;
        })->all();
        $this->assertSame($before, $after);
        $this->assertEquals($historyBefore, DB::table('order_status_changes')->orderBy('id')->get()->all());
    }
}
