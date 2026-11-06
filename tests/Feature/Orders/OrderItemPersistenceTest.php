<?php

namespace Tests\Feature\Orders;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class OrderItemPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_OPERATOR, 'is_active' => true]));
    }

    public function test_comment_edit_preserves_item_id_historical_cost_and_import_metadata(): void
    {
        [$order, $item, $product] = $this->fixture();
        $product->update(['cost_price' => 200]);
        $payload = $this->payload([$this->row($item, ['cost_price' => 1])]);
        $payload['order']['comment_internal'] = 'Оновлено лише коментар';

        $this->putJson('/orders/'.$order->id, $payload)->assertOk();

        $stored = $item->fresh();
        $this->assertNotNull($stored);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertSame(100.0, (float) $stored->cost_price);
        $this->assertSame('Білий', $stored->color);
        $this->assertSame('WEB-MODEL-38', $stored->external_id);
        $this->assertSame('Історична назва', $stored->product_title);
        $this->assertSame('Оновлено лише коментар', $order->fresh()->comment_internal);
    }

    public function test_quantity_and_price_changes_do_not_replace_the_historical_cost(): void
    {
        [$order, $item, $product] = $this->fixture();
        $product->update(['cost_price' => 200]);

        $this->putJson('/orders/'.$order->id, $this->payload([
            $this->row($item, ['qty' => 5, 'price' => 450, 'cost_price' => 999]),
        ]))->assertOk();

        $stored = $item->fresh();
        $this->assertNotNull($stored);
        $this->assertSame(100.0, (float) $stored->cost_price);
        $this->assertSame(5, (int) $stored->qty);
        $this->assertSame(450.0, (float) $stored->price);
        $this->assertSame(2250.0, (float) $stored->total);
    }

    public function test_unknown_historical_cost_remains_null_after_catalog_cost_is_added(): void
    {
        [$order, $item, $product] = $this->fixture();
        $item->update(['cost_price' => null]);
        $product->update(['cost_price' => 200]);

        $this->putJson('/orders/'.$order->id, $this->payload([$this->row($item)]))->assertOk();

        $this->assertNotNull($item->fresh());
        $this->assertNull($item->fresh()->cost_price);
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_zero_historical_cost_is_not_replaced_with_current_catalog_cost(): void
    {
        [$order, $item, $product] = $this->fixture();
        $item->update(['cost_price' => 0]);
        $product->update(['cost_price' => 200]);

        $this->putJson('/orders/'.$order->id, $this->payload([
            $this->row($item, ['qty' => 3, 'cost_price' => 999]),
        ]))->assertOk();

        $this->assertNotNull($item->fresh());
        $this->assertSame(0.0, (float) $item->fresh()->cost_price);
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_update_response_returns_the_saved_comment_and_currency(): void
    {
        [$order, $item] = $this->fixture();
        $payload = $this->payload([$this->row($item)]);
        $payload['order']['comment_internal'] = 'Актуальний коментар відповіді';
        $payload['order']['currency'] = 'EUR';
        $payload['payment']['currency'] = 'EUR';

        $this->putJson('/orders/'.$order->id, $payload)->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.comment_internal', 'Актуальний коментар відповіді')
            ->assertJsonPath('data.currency', 'EUR');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id, 'comment_internal' => 'Актуальний коментар відповіді', 'currency' => 'EUR',
        ]);
    }

    public function test_omitted_metadata_does_not_clear_the_existing_snapshot_or_product_links(): void
    {
        [$order, $item, $product] = $this->fixture();
        $product->update(['cost_price' => 200]);
        $before = $item->getAttributes();

        $this->putJson('/orders/'.$order->id, $this->payload([[
            'id' => $item->id, 'qty' => 2, 'price' => 399,
        ]]))->assertOk();

        $stored = $item->fresh();
        $this->assertNotNull($stored);
        foreach (['product_id', 'product_variant_id', 'product_title', 'sku', 'size', 'color', 'external_id', 'cost_price'] as $field) {
            $this->assertSame($before[$field], $stored->getAttributes()[$field], $field);
        }
    }

    public function test_new_row_uses_current_server_cost_even_when_it_duplicates_an_existing_product(): void
    {
        [$order, $item, $product] = $this->fixture();
        $product->update(['cost_price' => 200]);

        $this->putJson('/orders/'.$order->id, $this->payload([
            $this->row($item),
            $this->row($item, ['id' => null, 'qty' => 1, 'price' => 500, 'cost_price' => 1]),
        ]))->assertOk();

        $this->assertNotNull($item->fresh());
        $this->assertSame(100.0, (float) $item->fresh()->cost_price);
        $new = $order->items()->whereKeyNot($item->id)->sole();
        $this->assertSame(200.0, (float) $new->cost_price);
        $this->assertSame($product->id, $new->product_id);
        $this->assertSame(500.0, (float) $new->total);
        $this->assertDatabaseCount('order_items', 2);
    }

    public function test_creating_an_order_takes_cost_from_the_catalog_instead_of_the_client(): void
    {
        [$order, $item, $product] = $this->fixture();
        $product->update(['cost_price' => 200]);
        $row = $this->row($item, ['cost_price' => 1]);
        unset($row['id']);

        $response = $this->postJson('/orders', $this->payload([$row], false))->assertCreated();
        $newOrder = Order::findOrFail($response->json('data.id'));

        $this->assertNotSame($order->id, $newOrder->id);
        $this->assertSame(200.0, (float) $newOrder->items()->sole()->cost_price);
        $this->assertSame(100.0, (float) $item->fresh()->cost_price);
    }

    public static function replacementTypes(): iterable
    {
        yield 'заміна товару' => ['product'];
        yield 'заміна варіанта того самого товару' => ['variant'];
    }

    #[DataProvider('replacementTypes')]
    public function test_explicit_replacement_keeps_row_id_but_snapshots_current_server_cost(string $type): void
    {
        [$order, $item, $product] = $this->fixture();
        $product->update(['cost_price' => 200]);
        $replacement = $type === 'product'
            ? Product::create(['title' => 'Інший товар', 'sku' => 'OTHER', 'cost_price' => 300])
            : $product;
        $variant = $replacement->variants()->create(['size' => '42', 'sku' => 'REPLACEMENT-42', 'stock_qty' => 1]);

        $this->putJson('/orders/'.$order->id, $this->payload([
            $this->row($item, [
                'product_id' => $replacement->id, 'product_variant_id' => $variant->id,
                'title' => $replacement->title, 'sku' => $variant->sku, 'size' => $variant->size,
                'cost_price' => 1,
            ]),
        ]))->assertOk();

        $stored = $item->fresh();
        $this->assertNotNull($stored);
        $this->assertSame($replacement->id, $stored->product_id);
        $this->assertSame($variant->id, $stored->product_variant_id);
        $this->assertSame($type === 'product' ? 300.0 : 200.0, (float) $stored->cost_price);
        $this->assertDatabaseCount('order_items', 1);
    }

    public static function invalidItemIds(): iterable
    {
        yield 'ID іншого замовлення' => ['foreign'];
        yield 'неіснуючий ID' => ['missing'];
        yield 'повторний ID' => ['duplicate'];
    }

    public static function invalidProductVariantPairs(): iterable
    {
        yield 'варіант без товару' => ['without_product'];
        yield 'варіант іншого товару' => ['foreign_parent'];
    }

    #[DataProvider('invalidProductVariantPairs')]
    public function test_invalid_product_variant_pair_is_rejected_atomically(string $case): void
    {
        [$order, $item, $product] = $this->fixture();
        $other = Product::create(['title' => 'Інший товар', 'cost_price' => 300]);
        $foreignVariant = $other->variants()->create(['size' => '42', 'sku' => 'OTHER-42', 'stock_qty' => 1]);
        $orderBefore = $order->getAttributes();
        $itemBefore = $item->getAttributes();
        $row = $this->row($item, match ($case) {
            'without_product' => ['product_id' => null],
            'foreign_parent' => ['product_id' => $product->id, 'product_variant_id' => $foreignVariant->id],
        });
        $payload = $this->payload([$row]);
        $payload['order']['comment_internal'] = 'Не зберігати некоректну пару';
        $payload['customer']['first_name'] = 'Недопустима зміна';

        $this->putJson('/orders/'.$order->id, $payload)
            ->assertUnprocessable()->assertJsonStructure(['errors']);

        $this->assertSame($orderBefore, $order->fresh()->getAttributes());
        $this->assertSame($itemBefore, $item->fresh()?->getAttributes());
        $this->assertSame('Іван', $order->customer->fresh()->first_name);
        $this->assertDatabaseCount('order_items', 1);
    }

    #[DataProvider('invalidItemIds')]
    public function test_invalid_item_ids_are_rejected_atomically(string $case): void
    {
        [$order, $item] = $this->fixture();
        $otherOrder = Order::create(['order_number' => 'OTHER-ORDER', 'currency' => 'UAH']);
        $foreign = $otherOrder->items()->create([
            'product_title' => 'Чужа позиція', 'sku' => 'FOREIGN', 'qty' => 1,
            'price' => 100, 'cost_price' => 50, 'total' => 100,
        ])->fresh();
        $orderBefore = $order->getAttributes();
        $itemBefore = $item->getAttributes();
        $foreignBefore = $foreign->getAttributes();
        $rows = match ($case) {
            'foreign' => [$this->row($item), $this->row($foreign)],
            'missing' => [$this->row($item), $this->row($item, ['id' => 999999])],
            'duplicate' => [$this->row($item), $this->row($item, ['qty' => 9])],
        };
        $payload = $this->payload($rows);
        $payload['customer']['first_name'] = 'Ця зміна не повинна зберегтися';
        $payload['order']['comment_internal'] = 'Недопустимий запит';

        $this->putJson('/orders/'.$order->id, $payload)
            ->assertUnprocessable()->assertJsonStructure(['errors']);

        $this->assertSame($orderBefore, $order->fresh()->getAttributes());
        $this->assertSame($itemBefore, $item->fresh()?->getAttributes());
        $this->assertSame($foreignBefore, $foreign->fresh()?->getAttributes());
        $this->assertSame('Іван', $order->customer->fresh()->first_name);
        $this->assertDatabaseCount('order_items', 2);
    }

    public static function suppliedCreateIds(): iterable
    {
        yield 'наявний ID' => [false];
        yield 'неіснуючий ID' => [true];
    }

    #[DataProvider('suppliedCreateIds')]
    public function test_creating_an_order_cannot_supply_an_item_id(bool $missing): void
    {
        [$order, $item] = $this->fixture();

        $this->postJson('/orders', $this->payload([
            $this->row($item, ['id' => $missing ? 999999 : $item->id]),
        ], false))->assertUnprocessable()->assertJsonStructure(['errors']);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertSame($order->id, $item->fresh()->order_id);
    }

    public function test_only_explicitly_deleted_item_is_removed(): void
    {
        [$order, $item] = $this->fixture();
        $removed = $order->items()->create([
            'product_title' => 'Видаляється', 'sku' => 'REMOVED', 'qty' => 1,
            'price' => 100, 'cost_price' => 50, 'total' => 100,
        ]);
        $payload = $this->payload([$this->row($item)]);
        $payload['deleted_item_ids'] = [$removed->id];

        $this->putJson('/orders/'.$order->id, $payload)->assertOk();

        $this->assertNotNull($item->fresh());
        $this->assertDatabaseMissing('order_items', ['id' => $removed->id]);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertSame(100.0, (float) $item->fresh()->cost_price);
    }

    public function test_stale_form_does_not_delete_a_position_added_elsewhere(): void
    {
        [$order, $item] = $this->fixture();
        $unseen = $order->items()->create([
            'product_title' => 'Додано іншим менеджером', 'sku' => 'UNSEEN', 'qty' => 1,
            'price' => 300, 'cost_price' => 150, 'total' => 300, 'external_id' => 'UNSEEN-EXTERNAL',
        ])->fresh();
        $before = $unseen->getAttributes();

        $this->putJson('/orders/'.$order->id, $this->payload([
            $this->row($item, ['qty' => 3]),
        ]))->assertOk();

        $this->assertSame($before, $unseen->fresh()?->getAttributes());
        $this->assertNotNull($item->fresh());
        $this->assertSame(3, (int) $item->fresh()->qty);
        $this->assertSame(1197.0, (float) $item->fresh()->total);
        $this->assertDatabaseCount('order_items', 2);
    }

    public static function invalidDeletions(): iterable
    {
        yield 'ID іншого замовлення' => ['foreign'];
        yield 'неіснуючий ID' => ['missing'];
        yield 'повторний ID' => ['duplicate'];
        yield 'оновлення та видалення одного ID' => ['overlap'];
    }

    #[DataProvider('invalidDeletions')]
    public function test_invalid_deletion_does_not_partially_change_the_order(string $case): void
    {
        [$order, $item] = $this->fixture();
        $otherOrder = Order::create(['order_number' => 'OTHER-ORDER']);
        $foreign = $otherOrder->items()->create(['sku' => 'FOREIGN', 'qty' => 1, 'price' => 100, 'total' => 100])->fresh();
        $second = $order->items()->create(['sku' => 'SECOND', 'qty' => 1, 'price' => 100, 'total' => 100])->fresh();
        $orderBefore = $order->getAttributes();
        $itemBefore = $item->getAttributes();
        $foreignBefore = $foreign->getAttributes();
        $secondBefore = $second->getAttributes();
        $payload = $this->payload([$this->row($item)]);
        $payload['order']['comment_internal'] = 'Не зберігати';
        $payload['deleted_item_ids'] = match ($case) {
            'foreign' => [$foreign->id],
            'missing' => [999999],
            'duplicate' => [$second->id, $second->id],
            'overlap' => [$item->id],
        };

        $this->putJson('/orders/'.$order->id, $payload)
            ->assertUnprocessable()->assertJsonStructure(['errors']);

        $this->assertSame($orderBefore, $order->fresh()->getAttributes());
        $this->assertSame($itemBefore, $item->fresh()?->getAttributes());
        $this->assertSame($foreignBefore, $foreign->fresh()?->getAttributes());
        $this->assertSame($secondBefore, $second->fresh()?->getAttributes());
        $this->assertDatabaseCount('order_items', 3);
    }

    public function test_legacy_unique_product_variant_match_preserves_id_and_historical_cost(): void
    {
        [$order, $item, $product] = $this->fixture();
        $product->update(['cost_price' => 200]);
        $row = $this->row($item, ['sku' => 'Інша текстова назва SKU', 'qty' => 3]);
        unset($row['id']);

        $this->putJson('/orders/'.$order->id, $this->payload([$row], false))->assertOk();

        $this->assertNotNull($item->fresh());
        $this->assertSame(100.0, (float) $item->fresh()->cost_price);
        $this->assertSame(3, (int) $item->fresh()->qty);
        $this->assertSame('WEB-MODEL-38', $item->fresh()->external_id);
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_legacy_unlinked_rows_match_by_sku_and_size_instead_of_empty_product_ids(): void
    {
        [$order, $item] = $this->fixture();
        $item->update(['product_id' => null, 'product_variant_id' => null]);
        $item->refresh();
        $second = $order->items()->create([
            'product_title' => 'Ручна позиція', 'sku' => 'MANUAL-42', 'size' => '42',
            'qty' => 1, 'price' => 300, 'cost_price' => 50, 'total' => 300,
        ])->fresh();
        $rows = [$this->row($item), $this->row($second)];
        foreach ($rows as &$row) {
            unset($row['id']);
        }
        unset($row);

        $this->putJson('/orders/'.$order->id, $this->payload($rows, false))->assertOk();

        $this->assertNotNull($item->fresh());
        $this->assertNotNull($second->fresh());
        $this->assertSame(100.0, (float) $item->fresh()->cost_price);
        $this->assertSame(50.0, (float) $second->fresh()->cost_price);
        $this->assertDatabaseCount('order_items', 2);
    }

    public function test_ambiguous_legacy_match_is_rejected_without_replacing_rows(): void
    {
        [$order, $item] = $this->fixture();
        $second = $item->replicate();
        $second->save();
        $second->refresh();
        $orderBefore = $order->getAttributes();
        $itemBefore = $item->getAttributes();
        $secondBefore = $second->getAttributes();
        $row = $this->row($item);
        unset($row['id']);

        $this->putJson('/orders/'.$order->id, $this->payload([$row], false))
            ->assertUnprocessable()->assertJsonStructure(['errors']);

        $this->assertSame($orderBefore, $order->fresh()->getAttributes());
        $this->assertSame($itemBefore, $item->fresh()?->getAttributes());
        $this->assertSame($secondBefore, $second->fresh()?->getAttributes());
        $this->assertDatabaseCount('order_items', 2);
    }

    public function test_unmatched_legacy_row_cannot_silently_replace_an_existing_item(): void
    {
        [$order, $item] = $this->fixture();
        $replacement = Product::create(['title' => 'Новий товар', 'sku' => 'NEW', 'cost_price' => 300]);
        $before = $item->getAttributes();

        $this->putJson('/orders/'.$order->id, $this->payload([[
            'product_id' => $replacement->id, 'title' => $replacement->title, 'sku' => 'NEW',
            'size' => '42', 'qty' => 1, 'price' => 500,
        ]], false))->assertUnprocessable()->assertJsonStructure(['errors']);

        $this->assertSame($before, $item->fresh()?->getAttributes());
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_update_rolls_back_item_order_and_customer_changes_if_new_item_creation_fails(): void
    {
        [$order, $item] = $this->fixture();
        $orderBefore = $order->getAttributes();
        $itemBefore = $item->getAttributes();
        $payload = $this->payload([
            $this->row($item, ['qty' => 4]),
            ['sku' => 'FAIL-ITEM', 'title' => 'Помилка створення', 'qty' => 1, 'price' => 500],
        ]);
        $payload['customer']['first_name'] = 'Невдала зміна';
        $payload['order']['comment_internal'] = 'Невдала зміна';
        $dispatcher = OrderItem::getEventDispatcher();
        OrderItem::setEventDispatcher(clone $dispatcher);

        try {
            $this->failCreatingItem();
            $this->putJson('/orders/'.$order->id, $payload)->assertStatus(500);
        } finally {
            OrderItem::setEventDispatcher($dispatcher);
        }

        $this->assertSame($orderBefore, $order->fresh()->getAttributes());
        $this->assertSame($itemBefore, $item->fresh()?->getAttributes());
        $this->assertSame('Іван', $order->customer->fresh()->first_name);
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_store_rolls_back_new_order_and_items_if_item_creation_fails(): void
    {
        [$order, $item] = $this->fixture();
        $row = $this->row($item);
        unset($row['id']);
        $dispatcher = OrderItem::getEventDispatcher();
        OrderItem::setEventDispatcher(clone $dispatcher);

        try {
            $this->failCreatingItem();
            $this->postJson('/orders', $this->payload([
                $row, ['sku' => 'FAIL-ITEM', 'title' => 'Помилка створення', 'qty' => 1, 'price' => 500],
            ], false))->assertStatus(500);
        } finally {
            OrderItem::setEventDispatcher($dispatcher);
        }

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('customers', 1);
        $this->assertSame($order->id, $item->fresh()->order_id);
    }

    private function fixture(): array
    {
        $product = Product::create(['title' => 'Каталожна назва', 'sku' => 'MODEL', 'cost_price' => 100]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'size' => '38', 'sku' => 'MODEL-38', 'stock_qty' => 6]);
        $customer = Customer::create([
            'first_name' => 'Іван', 'last_name' => 'Тест', 'phone' => '+380991112233',
            'phone_normalized' => PhoneNormalizer::normalize('+380991112233'),
        ]);
        $order = Order::create([
            'order_number' => 'ITEM-PERSISTENCE', 'customer_id' => $customer->id,
            'status' => 'new', 'payment_status' => 'unpaid', 'currency' => 'UAH',
            'comment_internal' => 'Початковий коментар',
        ]);
        $order->payment()->create(['method' => 'cod', 'currency' => 'UAH']);
        $order->delivery()->create(['carrier' => 'nova_poshta', 'delivery_type' => 'warehouse']);
        $item = $order->items()->create([
            'product_id' => $product->id, 'product_variant_id' => $variant->id,
            'product_title' => 'Історична назва', 'sku' => 'MODEL-38', 'size' => '38',
            'color' => 'Білий', 'external_id' => 'WEB-MODEL-38',
            'price' => 399, 'qty' => 2, 'total' => 798, 'cost_price' => 100,
        ]);

        return [$order->fresh(), $item->fresh(), $product->fresh()];
    }

    private function payload(array $items, bool $modern = true): array
    {
        $payload = [
            'customer' => ['first_name' => 'Іван', 'last_name' => 'Тест', 'phone' => '+380991112233'],
            'order' => ['status' => 'new', 'payment_status' => 'unpaid', 'currency' => 'UAH', 'comment_internal' => 'Початковий коментар'],
            'items' => $items,
            'payment' => ['method' => 'cod', 'currency' => 'UAH'],
            'delivery' => ['carrier' => 'nova_poshta', 'delivery_type' => 'warehouse'],
            'tag_ids' => [],
        ];
        if ($modern) {
            $payload['deleted_item_ids'] = [];
        }

        return $payload;
    }

    private function row(OrderItem $item, array $changes = []): array
    {
        return [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id,
            'title' => $item->product_title,
            'sku' => $item->sku,
            'size' => $item->size,
            'color' => $item->color,
            'external_id' => $item->external_id,
            'qty' => $item->qty,
            'price' => $item->price,
            ...$changes,
        ];
    }

    private function failCreatingItem(): void
    {
        OrderItem::creating(function (OrderItem $item): void {
            if ($item->sku === 'FAIL-ITEM') {
                throw new RuntimeException('Тестова помилка створення позиції.');
            }
        });
    }
}
