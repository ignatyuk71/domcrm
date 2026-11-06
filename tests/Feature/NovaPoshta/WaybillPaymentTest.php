<?php

namespace Tests\Feature\NovaPoshta;

use App\Models\Customer;
use App\Models\NovaPoshtaSetting;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WaybillPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        NovaPoshtaSetting::create(['api_key' => 'TEST-NP-KEY']);
        $this->actingAs(User::factory()->create([
            'role' => User::ROLE_OPERATOR,
            'is_active' => true,
        ]));
    }

    public static function paymentCases(): iterable
    {
        $defaults = [
            'payment_status' => 'unpaid',
            'method' => 'cod',
            'prepay_amount' => '0.00',
            'paid_amount' => null,
            'manual_paid' => false,
            'total' => '1000.00',
            'cost' => '1000',
            'afterpayment' => '1000',
        ];
        $cases = [
            'ручна повна оплата без суми платежу' => [
                'manual_paid' => true,
                'afterpayment' => null,
            ],
            'ручна повна оплата зі старою нульовою сумою' => [
                'manual_paid' => true,
                'paid_amount' => '0.00',
                'afterpayment' => null,
            ],
            'ручна повна оплата зі старою частковою сумою' => [
                'manual_paid' => true,
                'paid_amount' => '300.00',
                'afterpayment' => null,
            ],
            'підтверджена повна оплата' => [
                'payment_status' => 'paid',
                'paid_amount' => '1000.00',
                'afterpayment' => null,
            ],
            'підтверджена часткова оплата' => [
                'payment_status' => 'prepayment',
                'paid_amount' => '300.00',
                'afterpayment' => '700',
            ],
            'передоплата зі старого поля' => [
                'payment_status' => 'prepayment',
                'method' => 'prepay',
                'prepay_amount' => '300.00',
                'afterpayment' => '700',
            ],
            'нульова сума платежу не перекриває ручну передоплату' => [
                'payment_status' => 'prepayment',
                'method' => 'prepay',
                'prepay_amount' => '300.00',
                'paid_amount' => '0.00',
                'afterpayment' => '700',
            ],
            'підтверджена сума має пріоритет без додавання передоплати' => [
                'payment_status' => 'prepayment',
                'prepay_amount' => '100.00',
                'paid_amount' => '300.00',
                'afterpayment' => '700',
            ],
            'ручний аванс не перекривається старою підтвердженою сумою' => [
                'payment_status' => 'prepayment',
                'method' => 'prepay',
                'prepay_amount' => '100.00',
                'paid_amount' => '30.00',
                'afterpayment' => '900',
            ],
            'однакові суми не додаються двічі' => [
                'payment_status' => 'prepayment',
                'prepay_amount' => '300.00',
                'paid_amount' => '300.00',
                'afterpayment' => '700',
            ],
            'повернений платіж не зменшує післяплату' => [
                'payment_status' => 'refund',
                'paid_amount' => '1000.00',
                'afterpayment' => '1000',
            ],
            'повернення зберігає попередній розрахунок передоплати' => [
                'payment_status' => 'refund',
                'prepay_amount' => '100.00',
                'paid_amount' => '1000.00',
                'afterpayment' => '900',
            ],
            'неоплачене замовлення з післяплатою' => [],
            'банківський переказ без післяплати' => [
                'method' => 'card',
                'afterpayment' => null,
            ],
            'переплата без відʼємного залишку' => [
                'payment_status' => 'prepayment',
                'paid_amount' => '1200.00',
                'afterpayment' => null,
            ],
            'дробовий залишок округлюється до гривні' => [
                'payment_status' => 'prepayment',
                'total' => '1000.75',
                'paid_amount' => '300.20',
                'cost' => '1001',
                'afterpayment' => '701',
            ],
        ];

        foreach (['warehouse', 'courier'] as $deliveryType) {
            foreach ($cases as $label => $case) {
                yield $deliveryType.': '.$label => [$deliveryType, array_replace($defaults, $case)];
            }
        }
    }

    #[DataProvider('paymentCases')]
    public function test_waybill_uses_the_actual_unpaid_balance(string $deliveryType, array $case): void
    {
        $customer = Customer::create([
            'first_name' => 'Тест',
            'last_name' => 'Оплати',
            'phone' => '380501234567',
        ]);
        $order = Order::create([
            'order_number' => 'WAYBILL-PAYMENT-TEST',
            'customer_id' => $customer->id,
            'status' => 'new',
            'payment_status' => $case['payment_status'],
            'currency' => 'UAH',
        ]);
        $order->items()->create([
            'product_title' => 'Тестовий товар',
            'qty' => 1,
            'price' => $case['total'],
            'total' => $case['total'],
        ]);
        $order->payment()->create([
            'method' => $case['method'],
            'prepay_amount' => $case['prepay_amount'],
            'paid_amount' => $case['paid_amount'],
            'currency' => 'UAH',
        ]);
        OrderDelivery::create([
            'order_id' => $order->id,
            'carrier' => 'nova_poshta',
            'delivery_type' => $deliveryType,
            'city_ref' => 'city-ref',
            'settlement_ref' => 'settlement-ref',
            'warehouse_ref' => 'warehouse-ref',
            'street_ref' => 'street-ref',
            'street_name' => 'Хрещатик',
            'building' => '1',
        ]);

        if ($case['manual_paid']) {
            // Ручна позначка повної оплати не оновлює старі суми платежу.
            $this->patchJson('/orders/'.$order->id.'/payment-status', ['payment_status' => 'paid'])
                ->assertOk()
                ->assertJsonPath('data.payment_status', 'paid');
        }

        Http::fake([
            'https://api.novaposhta.ua/v2.0/json/' => function (Request $request) {
                if ($request['modelName'] === 'Counterparty' && $request['calledMethod'] === 'save') {
                    return Http::response(['success' => true, 'data' => [[
                        'Ref' => 'recipient-ref',
                        'ContactPerson' => ['data' => [['Ref' => 'contact-ref']]],
                    ]]]);
                }

                return Http::response(['success' => true, 'data' => [[
                    'IntDocNumber' => '20450000000001',
                    'Ref' => 'waybill-ref',
                ]]]);
            },
        ]);

        $action = $deliveryType === 'courier' ? 'generate-ttn-courier' : 'generate-ttn';
        $this->postJson('/orders/'.$order->id.'/'.$action)
            ->assertOk()
            ->assertJsonPath('ttn', '20450000000001');

        $requests = Http::recorded(fn (Request $request) => $request['modelName'] === 'InternetDocument'
            && $request['calledMethod'] === 'save');
        $this->assertCount(1, $requests);
        $properties = $requests->first()[0]['methodProperties'];

        // Оголошена вартість залишається повною, зменшується лише післяплата.
        $this->assertSame($case['cost'], $properties['Cost']);
        if ($case['afterpayment'] === null) {
            $this->assertArrayNotHasKey('AfterpaymentOnGoodsCost', $properties);
        } else {
            $this->assertSame($case['afterpayment'], $properties['AfterpaymentOnGoodsCost'] ?? null);
        }
        Http::assertSentCount($deliveryType === 'courier' ? 1 : 2);
    }
}
