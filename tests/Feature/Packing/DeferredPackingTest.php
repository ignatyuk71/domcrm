<?php

namespace Tests\Feature\Packing;

use App\Models\Order;
use App\Models\PackingSession;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeferredPackingTest extends TestCase
{
    use RefreshDatabase;

    private Status $queueStatus;
    private Status $packedStatus;
    private Status $shippedStatus;
    private int $orderSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->withoutVite();

        // Міграція розширює ENUM лише в MySQL; відтворюємо чинну схему в тестовому SQLite.
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('orders', function (Blueprint $table) {
                $table->enum('packing_status', ['pending', 'processing', 'packed', 'skipped'])
                    ->nullable()->default('pending')->change();
            });
        }

        $this->queueStatus = $this->orderStatus('packing', 'Упакування');
        $this->packedStatus = $this->orderStatus('packed', 'Запаковано');
        $this->shippedStatus = $this->orderStatus('shipped', 'Відправлено');

        config([
            'packing.status_codes.queue' => ['packing'],
            'packing.status_codes.packed' => ['packed'],
            'packing.status_codes.problem' => ['packing'],
            'packing.status_codes.shipped' => ['shipped'],
            'packing.auto_release_minutes' => 120,
        ]);
    }

    public function test_packer_and_operator_can_claim_deferred_orders(): void
    {
        foreach ([User::ROLE_PACKER, User::ROLE_OPERATOR] as $role) {
            $user = $this->worker($role);
            $order = $this->order();

            $this->actingAs($user)
                ->postJson("/packing/{$order->id}/start", ['queue' => 'skipped'])
                ->assertOk()
                ->assertJsonPath('success', true);

            $this->assertDatabaseHas('orders', [
                'id' => $order->id,
                'packing_status' => 'processing',
                'status_id' => $this->queueStatus->id,
                'packer_id' => $user->id,
            ]);
            $this->assertSame(1, $order->activePackingSession()->count());
            $this->assertSame($user->id, $order->activePackingSession()->firstOrFail()->packer_id);
        }
    }

    public function test_deferred_mode_rejects_orders_that_are_not_deferred(): void
    {
        $this->actingAs($this->worker());

        foreach (['pending', 'packed', null] as $packingStatus) {
            $order = $this->order(['packing_status' => $packingStatus]);

            $this->postJson("/packing/{$order->id}/start", ['queue' => 'skipped'])
                ->assertStatus(409);

            $this->assertSame($packingStatus, $order->fresh()->packing_status);
            $this->assertNull($order->fresh()->packer_id);
            $this->assertSame(0, $order->packingSessions()->count());
        }
    }

    public function test_deferred_mode_rejects_an_order_that_left_the_packing_queue(): void
    {
        $order = $this->order([
            'status_id' => $this->shippedStatus->id,
            'status' => 'shipped',
        ]);

        $this->actingAs($this->worker())
            ->postJson("/packing/{$order->id}/start", ['queue' => 'skipped'])
            ->assertStatus(409);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status_id' => $this->shippedStatus->id,
            'packing_status' => 'skipped',
            'packer_id' => null,
        ]);
        $this->assertDatabaseCount('packing_sessions', 0);
    }

    public function test_another_worker_cannot_take_a_claimed_deferred_order(): void
    {
        $firstWorker = $this->worker();
        $order = $this->order();

        $this->actingAs($firstWorker)
            ->postJson("/packing/{$order->id}/start", ['queue' => 'skipped'])
            ->assertOk();

        $session = $order->activePackingSession()->firstOrFail();
        // Новий прохід не повинен знімати чужого пакувальника через автоматичний таймаут.
        $session->update(['started_at' => now()->subHours(3)]);

        $this->actingAs($this->worker())
            ->postJson("/packing/{$order->id}/start", ['queue' => 'skipped'])
            ->assertStatus(423);

        $this->assertSame($firstWorker->id, $order->fresh()->packer_id);
        $this->assertSame('processing', $order->fresh()->packing_status);
        $this->assertNull($session->fresh()->finished_at);
        $this->assertSame(1, $order->packingSessions()->count());
    }

    public function test_repeating_own_start_preserves_the_active_session(): void
    {
        $worker = $this->worker();
        $order = $this->order();
        $this->actingAs($worker);

        $this->postJson("/packing/{$order->id}/start", ['queue' => 'skipped'])->assertOk();
        $session = $order->activePackingSession()->firstOrFail();

        $this->postJson("/packing/{$order->id}/start", ['queue' => 'skipped'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('packing_session_id', $session->id);

        $this->assertSame(1, $order->packingSessions()->count());
        $this->assertSame($session->id, $order->activePackingSession()->firstOrFail()->id);
        $this->assertTrue($session->started_at->equalTo($session->fresh()->started_at));
        $this->assertSame($worker->id, $order->fresh()->packer_id);
    }

    public function test_retry_does_not_resume_an_own_order_that_left_the_queue(): void
    {
        $worker = $this->worker();
        $order = $this->order();
        $this->actingAs($worker);
        $this->postJson("/packing/{$order->id}/start", ['queue' => 'skipped'])->assertOk();

        $order->refresh()->update([
            'status_id' => $this->shippedStatus->id,
            'status' => 'shipped',
        ]);

        $this->postJson("/packing/{$order->id}/start", ['queue' => 'skipped'])
            ->assertStatus(409);

        $this->assertSame($this->shippedStatus->id, $order->fresh()->status_id);
        $this->assertSame(1, $order->packingSessions()->count());
    }

    public function test_reloading_deferred_workspace_does_not_create_another_session(): void
    {
        $order = $this->order();
        $this->actingAs($this->worker());
        $this->postJson("/packing/{$order->id}/start", ['queue' => 'skipped'])->assertOk();

        for ($reload = 0; $reload < 2; $reload++) {
            $this->get("/packing/{$order->id}?queue=skipped")
                ->assertOk()
                ->assertViewIs('packing.app')
                ->assertViewHas('order', fn (Order $shown) => $shown->id === $order->id);
        }

        $this->assertSame(1, $order->packingSessions()->count());
        $this->assertSame('processing', $order->fresh()->packing_status);
    }

    public function test_deferred_workspace_does_not_claim_an_inactive_order_on_get(): void
    {
        $this->actingAs($this->worker());

        foreach (['skipped', 'packed', 'pending', null] as $packingStatus) {
            $order = $this->order(['packing_status' => $packingStatus]);

            $this->get("/packing/{$order->id}?queue=skipped")
                ->assertRedirect(route('packing.list'));

            $this->assertSame($packingStatus, $order->fresh()->packing_status);
            $this->assertNull($order->fresh()->packer_id);
            $this->assertSame(0, $order->packingSessions()->count());
        }
    }

    public function test_deferred_workspace_cannot_show_another_workers_order(): void
    {
        $firstWorker = $this->worker();
        $order = $this->order();
        $this->actingAs($firstWorker)
            ->postJson("/packing/{$order->id}/start", ['queue' => 'skipped'])
            ->assertOk();

        $this->actingAs($this->worker())
            ->get("/packing/{$order->id}?queue=skipped")
            ->assertRedirect(route('packing.list'));

        $this->assertSame($firstWorker->id, $order->fresh()->packer_id);
        $this->assertSame(1, $order->packingSessions()->count());
    }

    public function test_invalid_queue_values_are_rejected_without_claiming_the_order(): void
    {
        $order = $this->order();
        $this->actingAs($this->worker());

        foreach (['pending', 'unknown', ['skipped'], null, 1] as $queue) {
            $this->postJson("/packing/{$order->id}/start", ['queue' => $queue])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('queue');
        }

        $this->assertSame('skipped', $order->fresh()->packing_status);
        $this->assertNull($order->fresh()->packer_id);
        $this->assertDatabaseCount('packing_sessions', 0);
    }

    public function test_start_without_queue_keeps_the_normal_pending_workflow(): void
    {
        $order = $this->order(['packing_status' => 'pending']);
        $worker = $this->worker();

        $this->actingAs($worker)
            ->postJson("/packing/{$order->id}/start")
            ->assertOk();

        $this->assertSame('processing', $order->fresh()->packing_status);
        $this->assertSame($worker->id, $order->fresh()->packer_id);
    }

    public function test_repeated_actions_cannot_change_an_already_finished_deferred_order(): void
    {
        $order = $this->order();
        $this->actingAs($this->worker());
        $this->postJson("/packing/{$order->id}/start", ['queue' => 'skipped'])->assertOk();
        $this->postJson("/packing/{$order->id}/finish", ['queue' => 'skipped'])->assertOk();
        $closedSession = $order->packingSessions()->firstOrFail();

        foreach (['finish', 'problem'] as $action) {
            $this->postJson("/packing/{$order->id}/{$action}", ['queue' => 'skipped'])
                ->assertStatus(409);
        }

        $this->assertSame('packed', $order->fresh()->packing_status);
        $this->assertSame($this->packedStatus->id, $order->fresh()->status_id);
        $this->assertSame('finished', $closedSession->fresh()->close_reason);
        $this->assertTrue($closedSession->finished_at->equalTo($closedSession->fresh()->finished_at));
        $this->assertSame(0, $order->activePackingSession()->count());
    }

    public function test_repeating_problem_keeps_the_order_deferred_and_the_session_closed(): void
    {
        $order = $this->order();
        $this->actingAs($this->worker());
        $this->postJson("/packing/{$order->id}/start", ['queue' => 'skipped'])->assertOk();
        $this->postJson("/packing/{$order->id}/problem", ['queue' => 'skipped'])->assertOk();
        $closedSession = $order->packingSessions()->firstOrFail();

        $this->postJson("/packing/{$order->id}/problem", ['queue' => 'skipped'])->assertStatus(409);
        $this->postJson("/packing/{$order->id}/finish", ['queue' => 'skipped'])->assertForbidden();

        $this->assertSame('skipped', $order->fresh()->packing_status);
        $this->assertNull($order->fresh()->packer_id);
        $this->assertSame('problem', $closedSession->fresh()->close_reason);
        $this->assertTrue($closedSession->finished_at->equalTo($closedSession->fresh()->finished_at));
        $this->assertSame(0, $order->activePackingSession()->count());
    }

    public function test_another_worker_cannot_finish_or_defer_a_claimed_order(): void
    {
        $firstWorker = $this->worker();
        $order = $this->order();
        $this->actingAs($firstWorker)
            ->postJson("/packing/{$order->id}/start", ['queue' => 'skipped'])
            ->assertOk();

        $this->actingAs($this->worker());
        $this->postJson("/packing/{$order->id}/finish", ['queue' => 'skipped'])->assertForbidden();
        $this->postJson("/packing/{$order->id}/problem", ['queue' => 'skipped'])->assertStatus(409);

        $this->assertSame($firstWorker->id, $order->fresh()->packer_id);
        $this->assertSame('processing', $order->fresh()->packing_status);
        $this->assertSame(1, $order->activePackingSession()->count());
    }

    public function test_actions_do_not_overwrite_a_status_changed_while_packing(): void
    {
        $order = $this->order();
        $this->actingAs($this->worker());
        $this->postJson("/packing/{$order->id}/start", ['queue' => 'skipped'])->assertOk();
        $order->refresh()->update(['status_id' => $this->shippedStatus->id, 'status' => 'shipped']);

        foreach (['finish', 'problem'] as $action) {
            $this->postJson("/packing/{$order->id}/{$action}", ['queue' => 'skipped'])
                ->assertStatus(409);
        }

        $this->assertSame($this->shippedStatus->id, $order->fresh()->status_id);
        $this->assertSame('shipped', $order->fresh()->status);
        $this->assertSame(1, $order->activePackingSession()->count());
    }

    public function test_finishing_and_deferring_preserve_closed_orders_when_opening_the_next_one(): void
    {
        $worker = $this->worker();
        $finishedOrder = $this->order();
        $deferredAgain = $this->order();
        $nextOrder = $this->order();
        $this->actingAs($worker);

        $this->postJson("/packing/{$finishedOrder->id}/start", ['queue' => 'skipped'])->assertOk();
        $this->postJson("/packing/{$finishedOrder->id}/finish", ['queue' => 'skipped'])->assertOk();
        $this->postJson("/packing/{$deferredAgain->id}/start", ['queue' => 'skipped'])->assertOk();
        $this->postJson("/packing/{$deferredAgain->id}/problem", ['queue' => 'skipped'])->assertOk();
        $this->postJson("/packing/{$nextOrder->id}/start", ['queue' => 'skipped'])->assertOk();

        // Повернення до вже опрацьованих сторінок не відновлює закриті сесії.
        foreach ([$finishedOrder, $deferredAgain] as $closedOrder) {
            $this->get("/packing/{$closedOrder->id}?queue=skipped")
                ->assertRedirect(route('packing.list'));
            $this->assertSame(0, $closedOrder->activePackingSession()->count());
            $this->assertSame(1, $closedOrder->packingSessions()->count());
        }

        $this->assertSame('packed', $finishedOrder->fresh()->packing_status);
        $this->assertSame($this->packedStatus->id, $finishedOrder->fresh()->status_id);
        $this->assertSame('finished', $finishedOrder->packingSessions()->firstOrFail()->close_reason);
        $this->assertSame('skipped', $deferredAgain->fresh()->packing_status);
        $this->assertNull($deferredAgain->fresh()->packer_id);
        $this->assertSame('problem', $deferredAgain->packingSessions()->firstOrFail()->close_reason);
        $this->assertSame('processing', $nextOrder->fresh()->packing_status);
        $this->assertSame($worker->id, $nextOrder->fresh()->packer_id);
        $this->assertSame(1, PackingSession::whereNull('finished_at')->count());
    }

    public function test_session_id_makes_finish_and_problem_retries_idempotent(): void
    {
        $this->actingAs($this->worker());

        foreach (['finish' => 'packed', 'problem' => 'skipped'] as $action => $expectedStatus) {
            $order = $this->order();
            $sessionId = $this->postJson("/packing/{$order->id}/start", ['queue' => 'skipped'])
                ->assertOk()
                ->json('packing_session_id');
            $this->assertIsInt($sessionId);
            $payload = ['queue' => 'skipped', 'packing_session_id' => $sessionId];

            $this->postJson("/packing/{$order->id}/{$action}", $payload)->assertOk();
            $savedState = $this->persistedOrderState($order);
            $this->travel(2)->minutes();

            // Загублена HTTP-відповідь не повинна створювати нову історію чи змінювати час пакування.
            $this->postJson("/packing/{$order->id}/{$action}", $payload)
                ->assertOk()
                ->assertJsonPath('success', true);

            $this->assertSame($savedState, $this->persistedOrderState($order));
            $this->assertSame($expectedStatus, $order->fresh()->packing_status);
            $this->assertSame(0, $order->activePackingSession()->count());
        }
    }

    public function test_an_old_session_cannot_close_a_new_packing_session(): void
    {
        $order = $this->order();
        $this->actingAs($this->worker());
        $oldSessionId = $this->postJson("/packing/{$order->id}/start", ['queue' => 'skipped'])
            ->assertOk()->json('packing_session_id');
        $oldPayload = ['queue' => 'skipped', 'packing_session_id' => $oldSessionId];
        $this->postJson("/packing/{$order->id}/problem", $oldPayload)->assertOk();
        $newSessionId = $this->postJson("/packing/{$order->id}/start", ['queue' => 'skipped'])
            ->assertOk()->json('packing_session_id');
        $this->assertNotSame($oldSessionId, $newSessionId);
        $savedState = $this->persistedOrderState($order);
        $this->travel(2)->minutes();

        $this->postJson("/packing/{$order->id}/finish", $oldPayload)->assertStatus(409);
        // Повтор старого problem підтверджується, але нове пакування продовжує працювати.
        $this->postJson("/packing/{$order->id}/problem", $oldPayload)
            ->assertOk()->assertJsonPath('success', true);

        $this->assertSame($savedState, $this->persistedOrderState($order));
        $this->assertSame($newSessionId, $order->activePackingSession()->firstOrFail()->id);
        $this->assertSame('processing', $order->fresh()->packing_status);
    }

    public function test_session_ids_from_another_order_or_worker_cannot_be_used(): void
    {
        $firstWorker = $this->worker();
        $order = $this->order();
        $anotherOrder = $this->order();
        $this->actingAs($firstWorker);
        $sessionId = $this->postJson("/packing/{$order->id}/start", ['queue' => 'skipped'])
            ->assertOk()->json('packing_session_id');
        $anotherSessionId = $this->postJson("/packing/{$anotherOrder->id}/start", ['queue' => 'skipped'])
            ->assertOk()->json('packing_session_id');
        $savedState = $this->persistedOrderState($order);

        foreach (['finish', 'problem'] as $action) {
            foreach ([$anotherSessionId, 999999] as $invalidSessionId) {
                $this->postJson("/packing/{$order->id}/{$action}", [
                    'queue' => 'skipped', 'packing_session_id' => $invalidSessionId,
                ])->assertStatus(409);
            }
        }

        $this->actingAs($this->worker());
        foreach (['finish', 'problem'] as $action) {
            $this->postJson("/packing/{$order->id}/{$action}", [
                'queue' => 'skipped', 'packing_session_id' => $sessionId,
            ])->assertStatus(409);
        }

        $this->assertSame($savedState, $this->persistedOrderState($order));
    }

    public function test_a_session_closed_by_someone_else_is_not_an_own_retry(): void
    {
        $order = $this->order();
        $this->actingAs($this->worker());
        $sessionId = $this->postJson("/packing/{$order->id}/start", ['queue' => 'skipped'])
            ->assertOk()->json('packing_session_id');
        PackingSession::findOrFail($sessionId)->update([
            'finished_at' => now(),
            'closed_by' => $this->worker()->id,
            'close_reason' => 'finished',
        ]);
        $savedState = $this->persistedOrderState($order);

        $this->postJson("/packing/{$order->id}/finish", [
            'queue' => 'skipped', 'packing_session_id' => $sessionId,
        ])->assertStatus(409);

        $this->assertSame($savedState, $this->persistedOrderState($order));
    }

    public function test_deferred_actions_validate_the_optional_session_id(): void
    {
        $order = $this->order();
        $this->actingAs($this->worker());
        $this->postJson("/packing/{$order->id}/start", ['queue' => 'skipped'])->assertOk();
        $savedState = $this->persistedOrderState($order);

        foreach (['finish', 'problem'] as $action) {
            foreach ([0, -1, 1.5, 'wrong', [], null] as $invalidSessionId) {
                $this->postJson("/packing/{$order->id}/{$action}", [
                    'queue' => 'skipped', 'packing_session_id' => $invalidSessionId,
                ])->assertUnprocessable()->assertJsonValidationErrors('packing_session_id');
            }
        }

        $this->assertSame($savedState, $this->persistedOrderState($order));
    }

    public function test_return_to_list_recovers_all_own_unfinished_orders_without_waiting(): void
    {
        $worker = $this->worker();
        $this->actingAs($worker);
        $orders = [];
        for ($i = 0; $i < 7; $i++) {
            $order = $this->order(['packing_status' => 'pending']);
            $this->postJson("/packing/{$order->id}/start")->assertOk();
            $orders[] = $order;
        }
        $this->postJson('/packing/return-to-queue')->assertOk()->assertJsonPath('released', 7);
        foreach ($orders as $order) {
            $this->assertSame('pending', $order->fresh()->packing_status);
            $this->assertNull($order->fresh()->packer_id);
            $this->assertSame($this->queueStatus->id, $order->fresh()->status_id);
            $this->assertSame('returned_to_queue', $order->packingSessions()->firstOrFail()->close_reason);
            $this->assertSame(0, $order->activePackingSession()->count());
        }
        $this->postJson('/packing/return-to-queue')->assertOk()->assertJsonPath('released', 0);
        $this->postJson("/packing/{$orders[0]->id}/start")->assertOk();
        $this->assertSame('processing', $orders[0]->fresh()->packing_status);
    }

    public function test_return_to_queue_preserves_deferred_finished_other_worker_and_shipped_orders(): void
    {
        $worker = $this->worker();
        $this->actingAs($worker);
        $deferred = $this->order();
        $packed = $this->order();
        $this->postJson("/packing/{$packed->id}/start")->assertOk();
        $this->postJson("/packing/{$packed->id}/finish")->assertOk();
        $shipped = $this->order(['packing_status' => 'processing', 'packer_id' => $worker->id,
            'status_id' => $this->shippedStatus->id, 'status' => 'shipped']);
        $other = $this->order(['packing_status' => 'processing', 'packer_id' => $this->worker()->id]);
        $before = collect([$deferred, $packed, $shipped, $other])->map(fn ($order) => $this->persistedOrderState($order))->all();
        $this->postJson('/packing/return-to-queue')->assertOk()->assertJsonPath('released', 0);
        $after = collect([$deferred, $packed, $shipped, $other])->map(fn ($order) => $this->persistedOrderState($order))->all();
        $this->assertSame($before, $after);
    }

    public function test_only_explicit_problem_action_defers_an_order_and_old_workspace_cannot_change_released_order(): void
    {
        $this->actingAs($this->worker());
        $order = $this->order(['packing_status' => 'pending']);
        $this->postJson("/packing/{$order->id}/start")->assertOk();
        $this->postJson('/packing/return-to-queue')->assertOk();
        $this->postJson("/packing/{$order->id}/problem")->assertStatus(409);
        $this->postJson("/packing/{$order->id}/finish")->assertForbidden();
        $this->assertSame('pending', $order->fresh()->packing_status);
        $this->postJson("/packing/{$order->id}/start")->assertOk();
        $this->postJson("/packing/{$order->id}/problem")->assertOk();
        $this->postJson('/packing/return-to-queue')->assertOk()->assertJsonPath('released', 0);
        $this->assertSame('skipped', $order->fresh()->packing_status);
    }

    public function test_return_to_queue_requires_authentication(): void
    {
        $this->postJson('/packing/return-to-queue')->assertUnauthorized();
    }

    private function persistedOrderState(Order $order): array
    {
        return [
            'order' => $order->fresh()->getRawOriginal(),
            'sessions' => $order->packingSessions()->orderBy('id')->get()
                ->map(fn (PackingSession $session) => $session->getRawOriginal())->all(),
            'status_history' => DB::table('order_status_changes')->where('order_id', $order->id)
                ->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        ];
    }

    private function worker(string $role = User::ROLE_PACKER): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function orderStatus(string $code, string $name): Status
    {
        return Status::firstOrCreate(['code' => $code], ['name' => $name, 'type' => 'order']);
    }

    private function order(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'DEFERRED-'.++$this->orderSequence,
            'status_id' => $this->queueStatus->id,
            'status' => 'packing',
            'packing_status' => 'skipped',
            'payment_status' => 'unpaid',
            'currency' => 'UAH',
        ], $attributes));
    }
}
