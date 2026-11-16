<?php

namespace Tests\Feature\Orders;

use App\Http\Controllers\OrderController;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\Status;
use App\Models\User;
use App\Services\NovaPoshtaService;
use Database\Seeders\StatusesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Запобіжники генерації ТТН Нової Пошти (OrderController::generateTTN).
 * Перевіряємо статус замовлення до звернення до НП і повноту даних доставки.
 */
class OrderTtnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(StatusesSeeder::class);
        // Жодного реального запиту до Нової Пошти не має вилетіти.
        Http::preventStrayRequests();
    }

    private function operator(): User
    {
        return User::factory()->create(['role' => User::ROLE_OPERATOR, 'is_active' => true]);
    }

    private function orderWithDelivery(array $delivery, array $attributes = []): Order
    {
        $order = Order::create(array_merge([
            'order_number' => 'T-' . uniqid(),
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'currency' => 'UAH',
        ], $attributes));
        OrderDelivery::create(array_merge(['order_id' => $order->id], $delivery));

        return $order;
    }

    private function validDelivery(string $type): array
    {
        return [
            'delivery_type' => $type,
            'city_ref' => 'city-1',
            'settlement_ref' => 'settlement-1',
            'warehouse_ref' => 'wh-1',
            'street_ref' => 'street-1',
            'building' => '1',
        ];
    }

    private function rejectNovaPoshtaCalls(): NovaPoshtaService
    {
        return $this->mock(NovaPoshtaService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('createWaybill');
            $mock->shouldNotReceive('createWaybillCourier');
        });
    }

    private function allowNovaPoshtaCall(string $type): void
    {
        $this->mock(NovaPoshtaService::class, function (MockInterface $mock) use ($type) {
            $method = $type === 'courier' ? 'createWaybillCourier' : 'createWaybill';
            $mock->shouldReceive($method)->once()->andReturn([
                'success' => true,
                'data' => [['IntDocNumber' => '20450000000001', 'Ref' => 'waybill-ref']],
            ]);
        });
    }

    private function endpoint(Order $order, string $type): string
    {
        return '/orders/'.$order->id.($type === 'courier' ? '/generate-ttn-courier' : '/generate-ttn');
    }

    private function blockedMessage(string $name): string
    {
        return 'Неможливо створити ТТН у статусі «'.$name.'». Спочатку змініть статус замовлення на «Підтверджено».';
    }

    public static function blockedStatusCases(): iterable
    {
        foreach (['warehouse', 'courier'] as $type) {
            foreach (['new' => 'Новий', 'in_process' => 'В обробці', 'returned' => 'Повернення', 'cancelled' => 'Скасовано'] as $code => $name) {
                yield $type.': '.$code => [$type, $code, $name];
            }
        }
    }

    #[DataProvider('blockedStatusCases')]
    public function test_status_blocks_creation_before_delivery_validation_or_nova_poshta_calls(string $type, string $code, string $name): void
    {
        $status = Status::where('code', $code)->sole();
        // Поле legacy навмисно дозволене: рішення має прийматися за status_id.
        $order = $this->orderWithDelivery(['delivery_type' => $type], ['status_id' => $status->id]);
        $before = $order->delivery->getAttributes();
        $this->rejectNovaPoshtaCalls();

        $this->actingAs($this->operator())->postJson($this->endpoint($order, $type))
            ->assertUnprocessable()
            ->assertJsonPath('message', $this->blockedMessage($name));

        $this->assertSame($before, $order->fresh()->delivery->getAttributes());
        Http::assertNothingSent();
    }

    public static function allowedStatusCases(): iterable
    {
        foreach (['warehouse', 'courier'] as $type) {
            foreach (['confirmed', 'reserved', 'packing', 'packed', 'shipped', 'delivered', 'delivered_paid'] as $code) {
                yield $type.': '.$code => [$type, $code];
            }
        }
    }

    #[DataProvider('allowedStatusCases')]
    public function test_confirmed_and_following_active_statuses_allow_creation(string $type, string $code): void
    {
        $status = Status::where('code', $code)->sole();
        // Порядок відображення й старе текстове поле не визначають дозвіл.
        $status->update(['sort_order' => 0]);
        $order = $this->orderWithDelivery($this->validDelivery($type), ['status_id' => $status->id, 'status' => 'new']);
        $this->allowNovaPoshtaCall($type);

        $this->actingAs($this->operator())->postJson($this->endpoint($order, $type))
            ->assertOk()
            ->assertJsonPath('ttn', '20450000000001');

        $this->assertSame('20450000000001', $order->fresh()->delivery->ttn);
        $this->assertSame('waybill-ref', $order->delivery->ttn_ref);
    }

    public static function deliveryTypes(): array
    {
        return [['warehouse'], ['courier']];
    }

    #[DataProvider('deliveryTypes')]
    public function test_legacy_orders_without_status_id_are_also_checked(string $type): void
    {
        $order = $this->orderWithDelivery($this->validDelivery($type), ['status' => 'in_process']);
        $this->rejectNovaPoshtaCalls();

        $this->actingAs($this->operator())->postJson($this->endpoint($order, $type))
            ->assertUnprocessable()
            ->assertJsonPath('message', $this->blockedMessage('В обробці'));

        $this->assertNull($order->fresh()->delivery->ttn);
        Http::assertNothingSent();
    }

    #[DataProvider('deliveryTypes')]
    public function test_legacy_confirmed_orders_can_create_a_waybill(string $type): void
    {
        $order = $this->orderWithDelivery($this->validDelivery($type));
        $this->allowNovaPoshtaCall($type);

        $this->actingAs($this->operator())->postJson($this->endpoint($order, $type))
            ->assertOk()
            ->assertJsonPath('ttn', '20450000000001');
    }

    public static function invalidStatusCases(): iterable
    {
        foreach (['warehouse', 'courier'] as $type) {
            foreach (['unknown', 'payment'] as $case) {
                yield $type.': '.$case => [$type, $case];
            }
        }
    }

    #[DataProvider('invalidStatusCases')]
    public function test_unknown_or_non_order_status_cannot_allow_creation(string $type, string $case): void
    {
        $status = $case === 'payment'
            ? Status::where('code', 'confirmed')->sole()
            : Status::create(['code' => 'needs_review', 'name' => 'На перевірці', 'type' => 'order', 'sort_order' => 999]);
        if ($case === 'payment') {
            $status->update(['type' => 'payment']);
        }
        $order = $this->orderWithDelivery($this->validDelivery($type), ['status_id' => $status->id]);
        $this->rejectNovaPoshtaCalls();

        $this->actingAs($this->operator())->postJson($this->endpoint($order, $type))
            ->assertUnprocessable()
            ->assertJsonPath('message', $this->blockedMessage($status->name));

        $this->assertNull($order->fresh()->delivery->ttn);
        Http::assertNothingSent();
    }

    #[DataProvider('deliveryTypes')]
    public function test_controller_refreshes_status_before_creating_a_waybill(string $type): void
    {
        $confirmed = Status::where('code', 'confirmed')->sole();
        $cancelled = Status::where('code', 'cancelled')->sole();
        $order = $this->orderWithDelivery($this->validDelivery($type), ['status_id' => $confirmed->id]);
        $order->load('statusRef');
        // Модель контролера застаріла після зміни статусу в іншій вкладці.
        DB::table('orders')->where('id', $order->id)->update(['status' => 'cancelled', 'status_id' => $cancelled->id]);
        $service = $this->rejectNovaPoshtaCalls();
        $method = $type === 'courier' ? 'generateTTNCourier' : 'generateTTN';

        try {
            app(OrderController::class)->{$method}($order, $service);
            $this->fail('Застарілий дозволений статус не повинен дозволяти створення ТТН.');
        } catch (ValidationException $error) {
            $this->assertSame(422, $error->status);
            $this->assertSame([$this->blockedMessage('Скасовано')], $error->errors()['status']);
        }

        $this->assertNull($order->fresh()->delivery->ttn);
        Http::assertNothingSent();
    }

    public function test_ttn_rejected_without_city(): void
    {
        $order = $this->orderWithDelivery([
            'delivery_type' => 'warehouse',
            'city_ref' => null,
            'warehouse_ref' => 'wh-1',
        ]);

        $this->actingAs($this->operator())
            ->postJson("/orders/{$order->id}/generate-ttn")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Не заповнені дані міста']);

        $this->assertNull($order->fresh()->delivery->ttn);
    }

    public function test_ttn_rejected_without_warehouse(): void
    {
        $order = $this->orderWithDelivery([
            'delivery_type' => 'warehouse',
            'city_ref' => 'city-1',
            'warehouse_ref' => null,
        ]);

        $this->actingAs($this->operator())
            ->postJson("/orders/{$order->id}/generate-ttn")
            ->assertStatus(422);

        $this->assertNull($order->fresh()->delivery->ttn);
    }
}
