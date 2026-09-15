<?php

namespace Tests\Feature\NovaPoshta;

use App\Models\NovaPoshtaSetting;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\Status;
use App\Models\User;
use App\Services\NovaPoshtaDeliveryCosts;
use Database\Seeders\StatusesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeliveryCostsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        NovaPoshtaSetting::create(['api_key' => 'TEST-NP-KEY']);
        $this->seed(StatusesSeeder::class);
    }

    public function test_cost_is_fetched_by_ttn_for_completed_orders_without_changing_status_or_fiscalizing(): void
    {
        $delivery = $this->delivery('delivered_paid');
        $order = $delivery->order;
        $this->fake($delivery, ['DocumentCost' => '96.25', 'PayerType' => 'Sender', 'Cost' => '1500']);
        $this->artisan('delivery:sync-costs')->assertSuccessful();
        $fresh = $delivery->fresh();
        $this->assertSame('96.25', (string) $fresh->np_document_cost);
        $this->assertSame('sender', $fresh->np_payer_type);
        $this->assertSame($delivery->ttn, $fresh->np_cost_ttn);
        $this->assertSame('2026-09-10 12:13:49', $fresh->np_document_date);
        $this->assertSame('999.00', $fresh->delivery_cost);
        $this->assertSame('recipient', $fresh->delivery_payer);
        $this->assertSame($order->status, $order->fresh()->status);
        $this->assertEquals($order->status_changed_at, $order->fresh()->status_changed_at);
        $this->assertDatabaseCount('fiscal_receipts', 0);
        $this->assertDatabaseCount('order_status_changes', 0);
        Http::assertSent(fn ($request) => $request['modelName'] === 'TrackingDocument'
            && $request['calledMethod'] === 'getStatusDocuments'
            && $request['methodProperties']['Documents'][0]['DocumentNumber'] === $delivery->ttn);
    }

    public function test_scheduled_and_manual_tracking_also_save_api_costs(): void
    {
        $delivery = $this->delivery('shipped');
        Http::fakeSequence('*api.novaposhta.ua*')
            ->push(['success' => true, 'data' => [$this->row($delivery, ['DocumentCost' => '90', 'PayerType' => 'Sender', 'StatusCode' => '5'])]])
            ->push(['success' => true, 'data' => [$this->row($delivery, ['DocumentCost' => '110.50', 'PayerType' => 'Recipient'])]]);
        $this->artisan('delivery:sync-statuses')->assertSuccessful();
        $this->assertEquals(90, $delivery->fresh()->np_document_cost);

        $owner = User::factory()->create(['role' => User::ROLE_OWNER, 'is_active' => true]);
        $this->actingAs($owner)->postJson('/orders/'.$delivery->order_id.'/track-delivery')->assertOk();
        $this->assertEquals(110.50, $delivery->fresh()->np_document_cost);
        $this->assertSame('recipient', $delivery->fresh()->np_payer_type);
    }

    public function test_missing_or_invalid_document_cost_is_never_replaced_by_declared_value_or_fixed_estimate(): void
    {
        $delivery = $this->delivery('delivered_paid');
        $service = app(NovaPoshtaDeliveryCosts::class);
        foreach ([null, '', '-90', 'not a price', '96.251', [], '100000000'] as $cost) {
            $service->store($delivery, $this->row($delivery, ['DocumentCost' => $cost, 'Cost' => 1500]), now());
            $this->assertNull($delivery->fresh()->np_document_cost);
        }
        $service->store($delivery, $this->row($delivery, ['DocumentCost' => '0']), now());
        $this->assertEquals(0, $delivery->fresh()->np_document_cost);
    }

    public function test_wrong_or_replaced_ttn_cannot_receive_an_old_price(): void
    {
        $delivery = $this->delivery('delivered_paid');
        $service = app(NovaPoshtaDeliveryCosts::class);
        $this->assertFalse($service->store($delivery, $this->row($delivery, ['Number' => '20450000009999']), now()));
        DB::table('order_deliveries')->where('id', $delivery->id)->update(['ttn' => '20450000000002']);
        $this->assertFalse($service->store($delivery, $this->row($delivery, ['DocumentCost' => '90']), now()));
        $this->assertNull($delivery->fresh()->np_document_cost);
    }

    public function test_api_failure_keeps_last_price_and_does_not_block_other_unchecked_waybills(): void
    {
        $delivery = $this->delivery('delivered_paid');
        app(NovaPoshtaDeliveryCosts::class)->store($delivery, $this->row($delivery, ['DocumentCost' => '90']), now()->subDays(2));
        Http::fake(['*api.novaposhta.ua*' => Http::response(['success' => false, 'errors' => ['temporary failure']])]);
        $this->artisan('delivery:sync-costs')->assertFailed();
        $this->assertEquals(90, $delivery->fresh()->np_document_cost);
        $this->assertNotNull($delivery->fresh()->np_cost_attempted_at);
        Http::assertSentCount(1);
    }

    private function delivery(string $status): OrderDelivery
    {
        $order = Order::create(['order_number' => 'NP-COST', 'status' => $status, 'status_id' => Status::where('code', $status)->value('id'), 'currency' => 'UAH']);

        return OrderDelivery::create(['order_id' => $order->id, 'carrier' => 'nova_poshta', 'delivery_type' => 'warehouse', 'ttn' => '20450000000001', 'delivery_payer' => 'recipient', 'delivery_cost' => 999]);
    }

    private function row(OrderDelivery $delivery, array $fields): array
    {
        return array_merge(['Number' => $delivery->ttn, 'StatusCode' => '9', 'Status' => 'Отримано', 'PayerType' => 'Sender', 'DateCreated' => '10-09-2026 12:13:49'], $fields);
    }

    private function fake(OrderDelivery $delivery, array $fields): void
    {
        Http::fake(['*api.novaposhta.ua*' => Http::response(['success' => true, 'data' => [$this->row($delivery, $fields)]])]);
    }
}
