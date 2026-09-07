<?php

namespace Tests\Feature\Orders;

use App\Models\NovaPoshtaSetting;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderStatusChange;
use App\Models\Status;
use App\Models\User;
use Database\Seeders\StatusesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class OrderStatusAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(StatusesSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_manual_status_change_records_operator_and_status_snapshots(): void
    {
        $this->travelTo(now()->startOfSecond());
        $order = $this->order();
        $operator = $this->user(User::ROLE_OPERATOR);

        $this->actingAs($operator)->patchJson("/orders/{$order->id}/status", [
            'status_id' => $this->statusId('confirmed'),
            // Дані клієнта не можуть підмінити виконавця чи причину в журналі.
            'actor_id' => 999999,
            'source' => 'nova_poshta_sync',
            'reason' => 'Підроблена причина',
        ])->assertOk();

        $change = OrderStatusChange::sole();
        $this->assertSame($order->id, (int) $change->order_id);
        $this->assertSame($order->order_number, $change->order_number);
        $this->assertSame('new', $change->old_status);
        $this->assertSame($this->statusId('new'), (int) $change->old_status_id);
        $this->assertSame('Новий', $change->old_status_name);
        $this->assertSame('confirmed', $change->new_status);
        $this->assertSame($this->statusId('confirmed'), (int) $change->new_status_id);
        $this->assertSame('Підтверджено', $change->new_status_name);
        $this->assertSame($operator->id, (int) $change->actor_id);
        $this->assertSame($operator->name, $change->actor_name);
        $this->assertSame('manual_status', $change->source);
        $this->assertNotSame('Підроблена причина', $change->reason);
        $this->assertSame('orders.updateStatus', $change->metadata['route']);
        $this->assertTrue($change->occurred_at->equalTo(now()));
    }

    public function test_creation_unchanged_status_and_other_fields_do_not_create_history(): void
    {
        $order = $this->order();
        $order->save();
        $order->update($this->statusAttributes('new'));
        $order->update(['comment_internal' => 'Уточнено комплектацію']);

        $this->assertDatabaseCount('order_status_changes', 0);
        $this->assertSame('Уточнено комплектацію', $order->fresh()->comment_internal);
    }

    public function test_stale_model_records_actual_previous_database_status(): void
    {
        $order = $this->order();
        $stale = $order->fresh();
        $order->update($this->statusAttributes('confirmed'));

        $stale->update($this->statusAttributes('shipped'));

        $changes = $order->statusChanges()->orderBy('id')->get();
        $this->assertCount(2, $changes);
        $this->assertSame('confirmed', $changes[1]->old_status);
        $this->assertSame($this->statusId('confirmed'), (int) $changes[1]->old_status_id);
        $this->assertSame('shipped', $changes[1]->new_status);
        $this->assertSame('shipped', $order->fresh()->status);
    }

    public function test_stale_model_repeating_an_already_saved_status_does_not_duplicate_history(): void
    {
        $order = $this->order();
        $stale = $order->fresh();
        $order->update($this->statusAttributes('confirmed'));

        $stale->update($this->statusAttributes('confirmed'));

        $this->assertDatabaseCount('order_status_changes', 1);
    }

    public function test_status_id_only_change_is_recorded_with_unchanged_legacy_code(): void
    {
        $order = $this->order();

        $order->update(['status_id' => $this->statusId('confirmed')]);

        $change = OrderStatusChange::sole();
        $this->assertSame('new', $change->old_status);
        $this->assertSame('new', $change->new_status);
        $this->assertSame($this->statusId('new'), (int) $change->old_status_id);
        $this->assertSame($this->statusId('confirmed'), (int) $change->new_status_id);
    }

    public function test_save_quietly_still_records_status_change(): void
    {
        $order = $this->order();

        $order->fill($this->statusAttributes('shipped'))->saveQuietly();

        $change = OrderStatusChange::sole();
        $this->assertSame('new', $change->old_status);
        $this->assertSame('shipped', $change->new_status);
        $this->assertSame('system', $change->source);
    }

    public function test_audit_failure_rolls_back_status_and_other_order_changes(): void
    {
        $order = $this->order();
        $event = 'eloquent.creating: '.OrderStatusChange::class;
        Event::listen($event, fn () => throw new RuntimeException('Тестовий збій запису журналу'));

        try {
            $order->updateWithStatusAudit([
                ...$this->statusAttributes('shipped'),
                'comment_internal' => 'Цей запис також має відкотитися',
            ], 'nova_poshta_sync', 'Тест атомарного запису');
            $this->fail('Зміна замовлення має відхилятися, якщо журнал недоступний.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Тестовий збій запису журналу', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $fresh = $order->fresh();
        $this->assertSame('new', $fresh->status);
        $this->assertSame($this->statusId('new'), (int) $fresh->status_id);
        $this->assertNull($fresh->comment_internal);
        $this->assertDatabaseCount('order_status_changes', 0);

        // Повторне використання моделі після винятку не успадковує контекст НП.
        $order->refresh()->update($this->statusAttributes('confirmed'));
        $this->assertSame('system', OrderStatusChange::sole()->source);
    }

    public function test_rejected_audit_insert_rolls_back_the_order_change(): void
    {
        $order = $this->order();
        $event = 'eloquent.creating: '.OrderStatusChange::class;
        Event::listen($event, fn () => false);

        try {
            $order->update($this->statusAttributes('shipped'));
            $this->fail('Відхилення запису журналу має відкотити зміну статусу.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Не вдалося записати зміну статусу в журнал.', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $this->assertSame('new', $order->fresh()->status);
        $this->assertDatabaseCount('order_status_changes', 0);
    }

    public function test_vetoed_order_update_does_not_create_history_or_leak_context(): void
    {
        $order = $this->order();
        $event = 'eloquent.updating: '.Order::class;
        Event::listen($event, fn () => false);

        try {
            $saved = $order->updateWithStatusAudit(
                $this->statusAttributes('shipped'), 'nova_poshta_sync', 'Відхилена зміна',
            );
        } finally {
            Event::forget($event);
        }

        $this->assertFalse($saved);
        $this->assertSame('new', $order->fresh()->status);
        $this->assertDatabaseCount('order_status_changes', 0);

        $order->refresh()->update($this->statusAttributes('confirmed'));
        $this->assertSame('system', OrderStatusChange::sole()->source);
    }

    public function test_explicit_context_is_cleared_after_success_and_no_op(): void
    {
        $order = $this->order();
        $order->updateWithStatusAudit($this->statusAttributes('confirmed'), 'nova_poshta_sync', 'Контекст першої зміни');
        $order->update($this->statusAttributes('packing'));
        $order->updateWithStatusAudit($this->statusAttributes('packing'), 'fiscal', 'Контекст без зміни');
        $order->update($this->statusAttributes('packed'));

        $this->assertSame(
            ['nova_poshta_sync', 'system', 'system'],
            $order->statusChanges()->orderBy('id')->pluck('source')->all(),
        );
    }

    public function test_cron_records_np_evidence_without_http_operator_or_private_payload(): void
    {
        $order = $this->orderWithDelivery();
        $this->actingAs($this->user(User::ROLE_OPERATOR));
        $this->fakeTracking($order, [
            'StatusCode' => '7',
            'Status' => 'Прибув у відділення',
            'StatusDescription' => 'Доступний для отримання',
            'RecipientFullName' => 'Приховане ім’я',
            'Phone' => '380991111111',
            'RecipientAddress' => 'Приватна адреса',
            'apiKey' => 'secret-never-log',
            'nested' => ['private' => 'secret-never-log'],
        ]);

        $this->artisan('delivery:sync-statuses')->assertSuccessful();

        $change = OrderStatusChange::sole();
        $this->assertSame('shipped', $change->old_status);
        $this->assertSame('delivered', $change->new_status);
        $this->assertSame('nova_poshta_sync', $change->source);
        $this->assertNull($change->actor_id);
        $this->assertNull($change->actor_name);
        $this->assertEquals([
            'ttn' => $order->delivery->ttn,
            'np_response' => [
                'StatusCode' => '7',
                'Status' => 'Прибув у відділення',
                'StatusDescription' => 'Доступний для отримання',
            ],
        ], $change->metadata);
        Http::assertSentCount(1);
    }

    public function test_manual_tracking_records_the_requesting_operator(): void
    {
        $order = $this->orderWithDelivery();
        $operator = $this->user(User::ROLE_OPERATOR);
        $this->fakeTracking($order, ['StatusCode' => '7', 'Status' => 'Прибув у відділення']);

        $this->actingAs($operator)->postJson("/orders/{$order->id}/track-delivery")->assertOk();

        $change = OrderStatusChange::sole();
        $this->assertSame('nova_poshta_manual', $change->source);
        $this->assertSame($operator->id, (int) $change->actor_id);
        $this->assertSame('orders.trackDelivery', $change->metadata['route']);
        $this->assertSame('7', $change->metadata['np_response']['StatusCode']);
    }

    public function test_waybill_not_found_does_not_produce_a_cancellation_or_status_history(): void
    {
        $order = $this->orderWithDelivery();
        $this->fakeTracking($order, ['StatusCode' => '3', 'Status' => 'Номер не знайдено']);

        $this->artisan('delivery:sync-statuses')->assertSuccessful();

        $this->assertSame('shipped', $order->fresh()->status);
        $this->assertDatabaseCount('order_status_changes', 0);
    }

    public function test_audit_metadata_accepts_only_bounded_scalar_evidence(): void
    {
        $order = $this->order();
        $order->updateWithStatusAudit($this->statusAttributes('confirmed'), 'nova_poshta_sync', 'Перевірка метаданих', [
            'ttn' => str_repeat('2', 50),
            'arbitrary' => 'Не журналювати',
            'np_response' => [
                'StatusCode' => 7,
                'Status' => str_repeat('я', 1200),
                'StatusDescription' => ['private' => 'Не журналювати'],
                'Phone' => '380991111111',
            ],
        ]);

        $metadata = OrderStatusChange::sole()->metadata;
        $this->assertSame(str_repeat('2', 32), $metadata['ttn']);
        $this->assertEqualsCanonicalizing(['StatusCode', 'Status'], array_keys($metadata['np_response']));
        $this->assertSame('7', $metadata['np_response']['StatusCode']);
        $this->assertSame(1000, mb_strlen($metadata['np_response']['Status']));
        $this->assertArrayNotHasKey('arbitrary', $metadata);
    }

    public static function historyRoles(): array
    {
        return [
            'owner' => [User::ROLE_OWNER, 200],
            'operator' => [User::ROLE_OPERATOR, 200],
            'packer' => [User::ROLE_PACKER, 403],
        ];
    }

    #[DataProvider('historyRoles')]
    public function test_history_requires_an_authorized_role(string $role, int $expectedStatus): void
    {
        $order = $this->order();

        $this->actingAs($this->user($role))
            ->getJson("/orders/{$order->id}/status-history")->assertStatus($expectedStatus);
    }

    public function test_history_rejects_guest_and_inactive_operator(): void
    {
        $order = $this->order();
        $this->getJson("/orders/{$order->id}/status-history")->assertUnauthorized();

        $inactive = $this->user(User::ROLE_OPERATOR);
        $inactive->update(['is_active' => false]);
        $this->actingAs($inactive)->getJson("/orders/{$order->id}/status-history")->assertForbidden();
    }

    public function test_history_is_paginated_newest_first_and_isolated_to_requested_order(): void
    {
        $order = $this->order();
        for ($index = 0; $index < 23; $index++) {
            $order->update($this->statusAttributes($index % 2 === 0 ? 'confirmed' : 'new'));
        }
        $other = $this->order();
        $other->update($this->statusAttributes('shipped'));
        $expectedIds = $order->statusChanges()->orderByDesc('id')->pluck('id')->all();
        $this->actingAs($this->user(User::ROLE_OPERATOR));

        $first = $this->getJson("/orders/{$order->id}/status-history")->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('data.0.source_label', 'Системне оновлення');
        $this->assertNotNull($first->json('next_page_url'));
        $this->assertSame(array_slice($expectedIds, 0, 20), array_column($first->json('data'), 'id'));
        $this->assertSame([$order->id], array_values(array_unique(array_column($first->json('data'), 'order_id'))));

        $second = $this->getJson("/orders/{$order->id}/status-history?page=2")->assertOk()
            ->assertJsonCount(3, 'data')->assertJsonPath('next_page_url', null);
        $this->assertSame(array_slice($expectedIds, 20), array_column($second->json('data'), 'id'));
        $this->assertSame([$order->id], array_values(array_unique(array_column($second->json('data'), 'order_id'))));
    }

    public function test_history_endpoint_is_read_only_and_unknown_order_returns_not_found(): void
    {
        $order = $this->order();
        $this->actingAs($this->user(User::ROLE_OWNER));

        $this->postJson("/orders/{$order->id}/status-history", [])->assertStatus(405);
        $this->patchJson("/orders/{$order->id}/status-history", [])->assertStatus(405);
        $this->deleteJson("/orders/{$order->id}/status-history")->assertStatus(405);
        $this->getJson('/orders/999999/status-history')->assertNotFound();
    }

    public function test_order_user_and_dictionary_deletion_preserve_audit_snapshots(): void
    {
        $order = $this->order();
        $operator = $this->user(User::ROLE_OPERATOR);
        $this->actingAs($operator)->patchJson("/orders/{$order->id}/status", [
            'status_id' => $this->statusId('confirmed'),
        ])->assertOk();
        $snapshot = OrderStatusChange::sole()->getRawOriginal();

        $order->delete();
        $operator->delete();
        Status::where('code', 'confirmed')->delete();

        $this->assertSame($snapshot, OrderStatusChange::sole()->getRawOriginal());
    }

    public function test_existing_audit_rows_cannot_be_updated_or_deleted_through_the_model(): void
    {
        $order = $this->order();
        $order->update($this->statusAttributes('confirmed'));
        $change = OrderStatusChange::sole();

        try {
            $change->update(['reason' => 'Підміна']);
            $this->fail('Історія має бути незмінною.');
        } catch (LogicException $exception) {
            $this->assertSame('Записи журналу не можна змінювати.', $exception->getMessage());
        }
        try {
            $change->delete();
            $this->fail('Історію не можна видаляти через модель.');
        } catch (LogicException $exception) {
            $this->assertSame('Записи журналу не можна видаляти.', $exception->getMessage());
        }

        $this->assertDatabaseCount('order_status_changes', 1);
        $this->assertNotSame('Підміна', $change->fresh()->reason);
    }

    private function order(string $status = 'new'): Order
    {
        return Order::create([
            'order_number' => 'AUDIT-'.bin2hex(random_bytes(6)),
            ...$this->statusAttributes($status),
            'payment_status' => 'unpaid',
            'currency' => 'UAH',
        ]);
    }

    private function statusAttributes(string $code): array
    {
        return ['status' => $code, 'status_id' => $this->statusId($code)];
    }

    private function statusId(string $code): int
    {
        return (int) Status::where('type', 'order')->where('code', $code)->value('id');
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function orderWithDelivery(): Order
    {
        NovaPoshtaSetting::create(['api_key' => 'TEST-NP-KEY']);
        $order = $this->order('shipped');
        OrderDelivery::create([
            'order_id' => $order->id,
            'carrier' => 'nova_poshta',
            'delivery_type' => 'warehouse',
            'ttn' => '20450000000001',
            'recipient_phone' => '380500000001',
        ]);

        return $order;
    }

    private function fakeTracking(Order $order, array $response): void
    {
        Http::fake([
            '*api.novaposhta.ua*' => Http::response([
                'success' => true,
                'data' => [['Number' => $order->delivery->ttn, ...$response]],
            ]),
        ]);
    }
}
