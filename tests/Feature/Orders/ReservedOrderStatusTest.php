<?php

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\Status;
use App\Models\User;
use Database\Seeders\StatusesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReservedOrderStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(StatusesSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_operator_can_reserve_an_order_and_filter_reservations_without_changing_other_confirmed_orders(): void
    {
        $confirmed = Status::where('code', 'confirmed')->sole();
        $reserved = Status::where('code', 'reserved')->sole();
        $order = $this->order('RESERVE-1', $confirmed);
        $untouched = $this->order('RESERVE-2', $confirmed);
        $before = $untouched->fresh()->getAttributes();
        $operator = User::factory()->create(['role' => User::ROLE_OPERATOR, 'is_active' => true]);

        $this->actingAs($operator)->patchJson("/orders/{$order->id}/status", ['status_id' => $reserved->id])
            ->assertOk()->assertJsonPath('data.code', 'reserved')->assertJsonPath('data.name', 'Бронювання');
        $this->assertSame('reserved', $order->fresh()->status);
        $this->assertSame($before, $untouched->fresh()->getAttributes());
        $this->assertDatabaseHas('order_status_changes', [
            'order_id' => $order->id,
            'old_status' => 'confirmed',
            'new_status' => 'reserved',
            'actor_id' => $operator->id,
        ]);
        $this->getJson('/orders/list?status_ids='.$reserved->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('status_counts.'.$reserved->id, 1);
        $this->assertSame('Підтверджено', $confirmed->fresh()->name);
    }

    public function test_migration_adds_status_idempotently_and_preserves_existing_orders_and_confirmed_status(): void
    {
        DB::table('statuses')->where('code', 'reserved')->delete();
        $confirmed = Status::where('code', 'confirmed')->sole();
        $order = $this->order('RESERVE-MIGRATION', $confirmed);
        $before = $order->fresh()->getAttributes();
        $confirmedBefore = $confirmed->getAttributes();
        $migration = require database_path('migrations/2026_09_11_000001_add_reserved_order_status.php');
        $migration->up();
        $migration->up();

        $this->assertSame(1, Status::where('code', 'reserved')->count());
        $this->assertDatabaseHas('statuses', ['code' => 'reserved', 'name' => 'Бронювання', 'type' => 'order', 'is_default' => false]);
        $this->assertSame($confirmedBefore, $confirmed->fresh()->getAttributes());
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertDatabaseCount('order_status_changes', 0);
    }

    public function test_repeat_migration_and_rollback_preserve_custom_status_and_existing_reservations(): void
    {
        $reserved = Status::where('code', 'reserved')->sole();
        $reserved->update(['name' => 'Бронювання товару', 'color' => '#123456']);
        $order = $this->order('RESERVE-EXISTING', $reserved);
        $statusBefore = $reserved->getAttributes();
        $orderBefore = $order->fresh()->getAttributes();
        $migration = require database_path('migrations/2026_09_11_000001_add_reserved_order_status.php');
        $migration->up();
        $migration->down();

        $this->assertSame($statusBefore, $reserved->fresh()->getAttributes());
        $this->assertSame($orderBefore, $order->fresh()->getAttributes());
    }

    private function order(string $number, Status $status): Order
    {
        return Order::create([
            'order_number' => $number,
            'status_id' => $status->id,
            'status' => $status->code,
            'payment_status' => 'unpaid',
            'currency' => 'UAH',
        ]);
    }
}
