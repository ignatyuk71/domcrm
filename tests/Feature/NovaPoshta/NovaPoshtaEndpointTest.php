<?php

namespace Tests\Feature\NovaPoshta;

use App\Models\Customer;
use App\Models\NovaPoshtaSetting;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\User;
use App\Services\NovaPoshtaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NovaPoshtaEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        NovaPoshtaSetting::create(['api_key' => 'TEST-NP-KEY']);
    }

    public static function addressSearchCases(): array
    {
        return [
            'місто' => [
                '/nova-poshta/cities?q='.rawurlencode('Київ'),
                'Address',
                'searchSettlements',
                ['CityName' => 'Київ', 'Page' => 1, 'Limit' => 20],
                [['Addresses' => [[
                    'Present' => 'м. Київ, Київська обл.',
                    'DeliveryCity' => 'city-ref',
                    'SettlementRef' => 'settlement-ref',
                    'Area' => 'Київська',
                    'SettlementTypeDescription' => 'місто',
                ]]]],
                [[
                    'ref' => 'city-ref',
                    'delivery_city_ref' => 'city-ref',
                    'settlement_ref' => 'settlement-ref',
                    'name' => 'м. Київ, Київська обл.',
                    'area' => 'Київська',
                    'type' => 'місто',
                ]],
            ],
            'відділення' => [
                '/nova-poshta/warehouses?city_ref=city-ref&q=123&limit=100',
                'AddressGeneral',
                'getWarehouses',
                ['CityRef' => 'city-ref', 'FindByString' => '123', 'Page' => 1, 'Limit' => 100],
                [[
                    'Ref' => 'warehouse-ref',
                    'Description' => 'Відділення №123',
                    'Category' => 'Branch',
                    'TypeOfWarehouse' => 'warehouse-type-ref',
                ]],
                [[
                    'ref' => 'warehouse-ref',
                    'name' => 'Відділення №123',
                    'category' => 'Branch',
                    'type' => 'warehouse-type-ref',
                ]],
            ],
            'вулиця за населеним пунктом' => [
                '/nova-poshta/streets?settlement_ref=settlement-ref&q='.rawurlencode('Хрещатик').'&limit=10',
                'Address',
                'searchSettlementStreets',
                ['SettlementRef' => 'settlement-ref', 'StreetName' => 'Хрещатик', 'Limit' => 10],
                [['Addresses' => [[
                    'SettlementStreetRef' => 'street-ref',
                    'Present' => 'вул. Хрещатик',
                    'StreetsType' => 'вул.',
                ]]]],
                [['ref' => 'street-ref', 'name' => 'вул. Хрещатик', 'type' => 'вул.']],
            ],
            'вулиця за містом' => [
                '/nova-poshta/streets?city_ref=city-ref&q='.rawurlencode('Хрещатик').'&limit=15',
                'AddressGeneral',
                'getStreet',
                ['CityRef' => 'city-ref', 'FindByString' => 'Хрещатик', 'Page' => 1, 'Limit' => 15],
                [['Ref' => 'street-ref', 'Description' => 'Хрещатик', 'StreetsType' => 'вул.']],
                [['ref' => 'street-ref', 'name' => 'Хрещатик']],
            ],
        ];
    }

    #[DataProvider('addressSearchCases')]
    public function test_address_search_works_when_the_primary_endpoint_cannot_connect(
        string $url,
        string $model,
        string $method,
        array $properties,
        array $apiData,
        array $expectedData,
    ): void {
        // Відтворюємо збій основного хоста, який спостерігався на сервері CRM.
        Http::fake([
            'https://api.novaposhta.ua/*' => Http::failedConnection('Connection timeout'),
            'https://api2.novaposhta.ua/v2.0/json/' => Http::response([
                'success' => true,
                'data' => $apiData,
            ]),
        ]);
        $owner = User::factory()->create(['role' => User::ROLE_OWNER, 'is_active' => true]);

        $this->actingAs($owner)->getJson($url)
            ->assertOk()
            ->assertExactJson(['data' => $expectedData]);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api2.novaposhta.ua/v2.0/json/'
            && $request->method() === 'POST'
            && $request['apiKey'] === 'TEST-NP-KEY'
            && $request['modelName'] === $model
            && $request['calledMethod'] === $method
            && $request['methodProperties'] === $properties);
    }

    public function test_tracking_keeps_using_the_primary_endpoint(): void
    {
        Http::fake([
            'https://api.novaposhta.ua/v2.0/json/' => Http::response(['success' => true, 'data' => []]),
        ]);
        $documents = [['DocumentNumber' => '20450000000001', 'Phone' => '380501234567']];

        $result = app(NovaPoshtaService::class)->getStatuses($documents);

        $this->assertTrue($result['success']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.novaposhta.ua/v2.0/json/'
            && $request['modelName'] === 'TrackingDocument'
            && $request['calledMethod'] === 'getStatusDocuments'
            && $request['methodProperties']['Documents'] === $documents);
    }

    public function test_waybill_deletion_keeps_the_primary_endpoint_without_retries(): void
    {
        Http::fake([
            'https://api.novaposhta.ua/v2.0/json/' => Http::response([
                'success' => false,
                'errors' => ['Тимчасова помилка'],
            ], 503),
        ]);

        $result = app(NovaPoshtaService::class)->deleteWaybill('waybill-ref');

        $this->assertFalse($result['success']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.novaposhta.ua/v2.0/json/'
            && $request['modelName'] === 'InternetDocument'
            && $request['calledMethod'] === 'delete'
            && $request['methodProperties']['DocumentRefs'] === ['waybill-ref']);
    }

    public static function waybillDeliveryTypes(): array
    {
        return ['відділення' => ['warehouse'], 'кур’єр' => ['courier']];
    }

    #[DataProvider('waybillDeliveryTypes')]
    public function test_waybill_creation_keeps_the_primary_endpoint_without_retries(string $deliveryType): void
    {
        Http::fake([
            'https://api.novaposhta.ua/v2.0/json/' => function (Request $request) {
                if ($request['modelName'] === 'Counterparty') {
                    return Http::response(['success' => true, 'data' => [[
                        'Ref' => 'recipient-ref',
                        'ContactPerson' => ['data' => [['Ref' => 'contact-ref']]],
                    ]]]);
                }

                // Після невизначеного результату створення повтор може дублювати ТТН.
                return Http::response(['success' => false, 'errors' => ['Тимчасова помилка']], 503);
            },
        ]);
        $customer = Customer::create(['first_name' => 'Тест', 'last_name' => 'Перевірка', 'phone' => '380501234567']);
        $order = Order::create([
            'order_number' => 'NP-ENDPOINT-TEST',
            'customer_id' => $customer->id,
            'status' => 'new',
            'payment_status' => 'unpaid',
            'currency' => 'UAH',
        ]);
        OrderDelivery::create([
            'order_id' => $order->id,
            'carrier' => 'nova_poshta',
            'delivery_type' => $deliveryType,
            'city_ref' => 'city-ref',
            'settlement_ref' => 'settlement-ref',
            'warehouse_ref' => 'warehouse-ref',
            'street_name' => 'Хрещатик',
            'building' => '1',
        ]);

        $result = app(NovaPoshtaService::class)->createWaybill($order);

        $this->assertFalse($result['success']);
        Http::assertSentCount($deliveryType === 'courier' ? 1 : 2);
        $waybillRequests = Http::recorded(fn (Request $request) => $request['modelName'] === 'InternetDocument');
        $this->assertCount(1, $waybillRequests);
        $request = $waybillRequests->first()[0];
        $this->assertSame('https://api.novaposhta.ua/v2.0/json/', $request->url());
        $this->assertSame('save', $request['calledMethod']);
    }

    public function test_sender_settings_keep_the_primary_endpoint_and_explicit_key(): void
    {
        Http::fakeSequence('https://api.novaposhta.ua/v2.0/json/')
            ->push(['success' => true, 'data' => [['Ref' => 'sender-ref']]])
            ->push(['success' => true, 'data' => [['Ref' => 'contact-ref', 'Phones' => '380501234567']]]);

        $result = app(NovaPoshtaService::class)->fetchRefsByApiKey('EXPLICIT-NP-KEY');

        $this->assertTrue($result['success']);
        $this->assertSame('sender-ref', $result['data']['sender_ref']);
        $this->assertSame('contact-ref', $result['data']['contact_ref']);
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('https://api.novaposhta.ua/v2.0/json/', $request->url());
            $this->assertSame('EXPLICIT-NP-KEY', $request['apiKey']);
            $this->assertSame('Counterparty', $request['modelName']);
        }
    }
}
