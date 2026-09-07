<?php

namespace Tests\Feature\NovaPoshta;

use App\Models\NovaPoshtaSetting;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\Status;
use App\Models\User;
use Database\Seeders\StatusesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeliveryTrackingStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        NovaPoshtaSetting::create(['api_key' => 'TEST-NP-KEY']);
        $this->seed(StatusesSeeder::class);
    }

    public static function unavailableWaybills(): array
    {
        $cases = [];

        foreach (['packing', 'shipped', 'delivered'] as $status) {
            $cases["deleted_{$status}"] = [2, 'Видалено', 'deleted', $status];
            $cases["not_found_{$status}"] = [3, 'Номер не знайдено', 'unknown', $status];
        }

        return $cases;
    }

    #[DataProvider('unavailableWaybills')]
    public function test_scheduled_tracking_preserves_order_for_unavailable_waybill(
        int $npCode,
        string $label,
        string $deliveryCode,
        string $orderStatus,
    ): void {
        $order = $this->orderWithDelivery($orderStatus);
        $this->fakeTracking($order, $npCode, $label);

        $this->artisan('delivery:sync-statuses')->assertSuccessful();

        $this->assertOrderPreservedAndTrackingSaved($order, $npCode, $label, $deliveryCode);
        Http::assertSentCount(1);
    }

    #[DataProvider('unavailableWaybills')]
    public function test_manual_tracking_preserves_order_for_unavailable_waybill(
        int $npCode,
        string $label,
        string $deliveryCode,
        string $orderStatus,
    ): void {
        $order = $this->orderWithDelivery($orderStatus);
        $this->fakeTracking($order, $npCode, $label);
        $operator = User::factory()->create(['role' => User::ROLE_OPERATOR, 'is_active' => true]);

        $this->actingAs($operator)
            ->postJson("/orders/{$order->id}/track-delivery")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('delivery_status_label', $label);

        $this->assertOrderPreservedAndTrackingSaved($order, $npCode, $label, $deliveryCode);
        Http::assertSentCount(1);
    }

    public function test_scheduled_tracking_rechecks_order_after_waybill_was_not_found(): void
    {
        $order = $this->orderWithDelivery('shipped');
        $ttn = $order->delivery->ttn;
        Http::fakeSequence('*api.novaposhta.ua*')
            ->push(['success' => true, 'data' => [[
                'Number' => $ttn,
                'StatusCode' => '3',
                'Status' => 'Номер не знайдено',
            ]]])
            ->push(['success' => true, 'data' => [[
                'Number' => $ttn,
                'StatusCode' => '7',
                'Status' => 'Прибув у відділення',
            ]]]);

        $this->artisan('delivery:sync-statuses')->assertSuccessful();
        $this->assertSame('shipped', $order->fresh()->status);

        // Помилка пошуку не вилучає замовлення з наступного запуску cron.
        $this->artisan('delivery:sync-statuses')->assertSuccessful();

        $fresh = $order->fresh();
        $this->assertSame('delivered', $fresh->status);
        $this->assertEquals(Status::where('code', 'delivered')->value('id'), $fresh->status_id);
        $this->assertSame('at_warehouse', $fresh->delivery->delivery_status_code);
        Http::assertSentCount(2);
    }

    private function orderWithDelivery(string $status): Order
    {
        $order = Order::create([
            'order_number' => 'NP-REGRESSION',
            'status' => $status,
            'status_id' => Status::where('code', $status)->value('id'),
            'payment_status' => 'unpaid',
            'currency' => 'UAH',
        ]);
        OrderDelivery::create([
            'order_id' => $order->id,
            'carrier' => 'nova_poshta',
            'delivery_type' => 'warehouse',
            'ttn' => '20450000000001',
            'recipient_phone' => '380500000001',
        ]);

        return $order;
    }

    private function fakeTracking(Order $order, int $npCode, string $label): void
    {
        Http::fake([
            '*api.novaposhta.ua*' => Http::response([
                'success' => true,
                'data' => [[
                    'Number' => $order->delivery->ttn,
                    'StatusCode' => (string) $npCode,
                    'Status' => $label,
                ]],
            ]),
        ]);
    }

    private function assertOrderPreservedAndTrackingSaved(
        Order $order,
        int $npCode,
        string $label,
        string $deliveryCode,
    ): void {
        $fresh = $order->fresh();
        $this->assertSame($order->status, $fresh->status);
        $this->assertSame($order->status_id, $fresh->status_id);
        $this->assertSame($deliveryCode, $fresh->delivery->delivery_status_code);
        $this->assertSame($label, $fresh->delivery->delivery_status_label);
        $this->assertNotNull($fresh->delivery->last_tracked_at);
        $this->assertDatabaseHas('order_delivery_status_histories', [
            'order_delivery_id' => $fresh->delivery->id,
            'source_code' => (string) $npCode,
            'status_label' => $label,
        ]);
    }
}
