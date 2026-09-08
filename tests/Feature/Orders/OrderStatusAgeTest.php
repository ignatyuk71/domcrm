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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class OrderStatusAgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(StatusesSeeder::class);
        $this->travelTo(now()->startOfSecond());
        Http::preventStrayRequests();
    }

    public static function saveModes(): array
    {
        return ['normal' => [false], 'quiet' => [true]];
    }

    #[DataProvider('saveModes')]
    public function test_creation_sets_the_first_status_time_without_a_history_event(bool $quiet): void
    {
        $order = new Order($this->attributes());
        $quiet ? $order->saveQuietly() : $order->save();

        $this->assertTrue($order->status_changed_at->equalTo(now()));
        $this->assertTrue($order->fresh()->status_changed_at->equalTo(now()));
        $this->assertDatabaseCount('order_status_changes', 0);
    }

    #[DataProvider('saveModes')]
    public function test_actual_status_change_sets_the_same_time_as_its_history_event(bool $quiet): void
    {
        $order = Order::create($this->attributes());
        $this->travel(3)->hours();
        $order->fill($this->statusAttributes('confirmed'));
        $quiet ? $order->saveQuietly() : $order->save();

        $this->assertTrue($order->status_changed_at->equalTo(now()));
        $this->assertTrue($order->fresh()->status_changed_at->equalTo(now()));
        $this->assertTrue(OrderStatusChange::sole()->occurred_at->equalTo($order->status_changed_at));
    }

    public function test_other_edits_no_op_and_stale_repeated_status_preserve_the_time(): void
    {
        $order = Order::create($this->attributes());
        $stale = $order->fresh();
        $this->travel(1)->hours();
        $order->update($this->statusAttributes('confirmed'));
        $changedAt = $order->status_changed_at->copy();

        $this->travel(3)->hours();
        $order->update(['comment_internal' => 'Уточнено адресу']);
        $order->update($this->statusAttributes('confirmed'));
        $stale->update($this->statusAttributes('confirmed'));

        $this->assertTrue($order->fresh()->status_changed_at->equalTo($changedAt));
        $this->assertTrue($stale->status_changed_at->equalTo($changedAt));
        $this->assertDatabaseCount('order_status_changes', 1);

        $this->travel(1)->hours();
        $stale->update($this->statusAttributes('shipped'));
        $this->assertTrue($stale->fresh()->status_changed_at->equalTo(now()));
        $this->assertDatabaseCount('order_status_changes', 2);
    }

    public function test_status_id_only_change_also_starts_a_new_duration(): void
    {
        $order = Order::create($this->attributes());
        $this->travel(1)->hours();

        $order->update(['status_id' => $this->statusId('confirmed')]);

        $this->assertTrue($order->fresh()->status_changed_at->equalTo(now()));
        $this->assertTrue(OrderStatusChange::sole()->occurred_at->equalTo(now()));
    }

    public function test_audit_failure_keeps_the_previous_status_time(): void
    {
        $order = Order::create($this->attributes());
        $changedAt = $order->status_changed_at->copy();
        $this->travel(1)->hours();
        $event = 'eloquent.creating: '.OrderStatusChange::class;
        Event::listen($event, fn () => throw new RuntimeException('Збій журналу'));

        try {
            $order->update($this->statusAttributes('confirmed'));
            $this->fail('Зміна має відкотитися разом із часом статусу.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Збій журналу', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $this->assertSame('new', $order->fresh()->status);
        $this->assertTrue($order->fresh()->status_changed_at->equalTo($changedAt));
        $this->assertTrue($order->status_changed_at->equalTo($changedAt));
        $this->assertDatabaseCount('order_status_changes', 0);
    }

    public function test_outer_transaction_rollback_also_rolls_back_the_new_status_time(): void
    {
        $order = Order::create($this->attributes());
        $changedAt = $order->status_changed_at->copy();
        $this->travel(1)->hours();

        try {
            DB::transaction(function () use ($order) {
                $order->update($this->statusAttributes('confirmed'));
                throw new RuntimeException('Відкат операції');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Відкат операції', $exception->getMessage());
        }

        $this->assertSame('new', $order->fresh()->status);
        $this->assertTrue($order->fresh()->status_changed_at->equalTo($changedAt));
        $this->assertDatabaseCount('order_status_changes', 0);
    }

    public function test_status_api_and_list_return_the_authoritative_iso_time(): void
    {
        $order = Order::create($this->attributes());
        $this->actingAs(User::factory()->create(['role' => User::ROLE_OPERATOR, 'is_active' => true]));
        $this->travel(2)->hours();
        $changedAt = now()->toJSON();

        $this->patchJson("/orders/{$order->id}/status", ['status_id' => $this->statusId('confirmed')])
            ->assertOk()->assertJsonPath('data.status_changed_at', $changedAt);
        $this->travel(1)->hours();
        $this->patchJson("/orders/{$order->id}/status", ['status_id' => $this->statusId('confirmed')])
            ->assertOk()->assertJsonPath('data.status_changed_at', $changedAt);
        $this->getJson(route('orders.list'))->assertOk()->assertJsonPath('data.0.status_changed_at', $changedAt);

        DB::table('orders')->where('id', $order->id)->update(['status_changed_at' => null]);
        $this->getJson(route('orders.list'))->assertOk()->assertJsonPath('data.0.status_changed_at', null);
    }

    public function test_manual_tracking_returns_updated_status_and_keeps_its_time_when_waybill_is_not_found(): void
    {
        NovaPoshtaSetting::create(['api_key' => 'TEST-NP-KEY']);
        $order = Order::create($this->attributes('shipped'));
        OrderDelivery::create([
            'order_id' => $order->id,
            'carrier' => 'nova_poshta',
            'delivery_type' => 'warehouse',
            'ttn' => '20450000000001',
            'recipient_phone' => '380500000001',
        ]);
        $this->actingAs(User::factory()->create(['role' => User::ROLE_OPERATOR, 'is_active' => true]));
        $this->travel(2)->hours();
        $changedAt = now()->toJSON();
        Http::fake(['*api.novaposhta.ua*' => Http::sequence()
            ->push(['success' => true, 'data' => [['Number' => '20450000000001', 'StatusCode' => '7', 'Status' => 'Прибув у відділення']]])
            ->push(['success' => true, 'data' => [['Number' => '20450000000001', 'StatusCode' => '3', 'Status' => 'Номер не знайдено']]])]);

        $this->postJson("/orders/{$order->id}/track-delivery")->assertOk()
            ->assertJsonPath('order_status.id', $this->statusId('delivered'))
            ->assertJsonPath('order_status.code', 'delivered')
            ->assertJsonPath('order_status.status_changed_at', $changedAt);
        $this->travel(1)->hours();
        $this->postJson("/orders/{$order->id}/track-delivery")->assertOk()
            ->assertJsonPath('order_status.code', 'delivered')
            ->assertJsonPath('order_status.status_changed_at', $changedAt);
        $this->assertDatabaseCount('order_status_changes', 1);
    }

    private function attributes(string $status = 'new'): array
    {
        return [
            'order_number' => 'AGE-'.bin2hex(random_bytes(6)),
            ...$this->statusAttributes($status),
            'payment_status' => 'unpaid',
            'currency' => 'UAH',
        ];
    }

    private function statusAttributes(string $code): array
    {
        return ['status' => $code, 'status_id' => $this->statusId($code)];
    }

    private function statusId(string $code): int
    {
        return (int) Status::where('type', 'order')->where('code', $code)->value('id');
    }
}
