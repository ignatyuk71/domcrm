<?php

namespace Tests\Feature\Orders;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_OPERATOR, 'is_active' => true]));
    }

    #[DataProvider('phoneSearches')]
    public function test_finds_orders_regardless_of_phone_format(string $search): void
    {
        // Кілька форматів збереження та відсутній blob відтворюють ручні й імпортовані замовлення.
        $orders = [];
        foreach (['0991112233', '+380 (99) 111-22-33', '380991112233'] as $phone) {
            $orders[] = $this->order(Customer::create([
                'phone' => $phone, 'phone_normalized' => '380991112233',
            ]))->id;
        }
        $this->order(Customer::create(['phone' => '0674445566', 'phone_normalized' => '380674445566']));

        $this->assertSearchReturns($search, array_reverse($orders));
    }

    public static function phoneSearches(): array
    {
        return [
            ['+380991112233'], ['380991112233'], ['0991112233'], ['991112233'],
            ['+38 (099) 111-22-33'], ['099 111 22 33'], ['099.111.22.33'],
            ['00380991112233'], ['80991112233'], ['  +380991112233  '],
            ["+380\u{00A0}(99)\u{202F}111\u{2011}22\u{2013}33"],
            ['11122'], ['111-22'], ['99111223'], ['809911122'],
        ];
    }

    #[DataProvider('legacyPhones')]
    public function test_searches_old_records_without_normalized_phone(string $phone, ?string $normalized): void
    {
        $order = $this->order(Customer::create(['phone' => $phone, 'phone_normalized' => $normalized]));
        $other = $this->order(Customer::create(['phone' => '0991112234']));

        $this->assertSearchReturns('+38 (099) 111-22-33', [$order->id]);
        $this->assertSearchReturns('0991112233', [$order->id]);
        $this->assertSearchReturns('11122', [$other->id, $order->id]);
    }

    public static function legacyPhones(): array
    {
        return [
            ['0991112233', null], ['+380 (99) 111-22-33', null],
            ['(099) 111 22 33', ''], ['00380991112233', null],
            ['991112233', null], ['80991112233', null],
            ["+380\u{00A0}(99)\u{202F}111\u{2011}22\u{2013}33", null],
        ];
    }

    public function test_searches_recipient_phone_even_when_customer_phone_is_different(): void
    {
        $order = $this->order(Customer::create(['phone' => '0674445566', 'phone_normalized' => '380674445566']));
        $order->delivery()->create(['delivery_type' => 'warehouse', 'recipient_phone' => '(099) 111-22-33']);

        $this->assertSearchReturns('+380991112233', [$order->id]);
        $this->assertSearchReturns('11122', [$order->id]);
    }

    public function test_preserves_search_by_order_number_name_email_and_ttn(): void
    {
        $customer = Customer::create(['first_name' => 'Іван', 'last_name' => 'Петренко', 'email' => 'buyer42@example.test']);
        $order = $this->order($customer, ['order_number' => 'DOM-0123456789']);
        $order->delivery()->create(['delivery_type' => 'warehouse', 'ttn' => '20451234567890']);
        $this->order(Customer::create(['phone' => '0421234567', 'phone_normalized' => '380421234567']));

        foreach (['DOM-0123456789', '0123456789', 'Іван', 'Петренко', 'Іван Петренко', 'Петренко Іван',
            'buyer42@example.test', '20451234567890', '2045 1234 5678 90', '567890'] as $search) {
            $this->assertSearchReturns($search, [$order->id]);
        }
    }

    public function test_digits_in_text_are_not_treated_as_a_separate_phone_search(): void
    {
        $this->order(Customer::create(['phone' => '0991112233', 'phone_normalized' => '380991112233']));

        $this->assertSearchReturns('DOM-11122', []);
        $this->assertSearchReturns('buyer11122@example.test', []);
        $this->assertSearchReturns('+380991112234', []);
        $this->assertSearchReturns('%', []);
        $this->assertSearchReturns('_', []);
    }

    public function test_preserves_leading_zeroes_and_searches_digits_in_order(): void
    {
        $order = $this->order(Customer::create(['phone' => '0990010203', 'phone_normalized' => '380990010203']));
        $other = $this->order(Customer::create(['phone' => '0991010203', 'phone_normalized' => '380991010203']));

        $this->assertSearchReturns('0010203', [$order->id]);
        $this->assertSearchReturns('000', []);
        $this->assertSearchReturns('11223', []);
        $this->assertSearchReturns('0', [$other->id, $order->id]);
    }

    public function test_search_remains_grouped_with_filters_and_pagination(): void
    {
        $status = Status::create(['name' => 'Підтверджено', 'code' => 'search_confirmed', 'type' => 'order']);
        $otherStatus = Status::create(['name' => 'Нове', 'code' => 'search_new', 'type' => 'order']);
        $customer = Customer::create(['phone' => '(099) 111-22-33', 'phone_normalized' => '380991112233']);
        $first = $this->order($customer, ['status_id' => $status->id]);
        $last = $this->order($customer, ['status_id' => $status->id]);
        $this->order($customer, ['status_id' => $otherStatus->id]);

        $query = ['search' => '+380991112233', 'status_ids' => $status->id, 'per_page' => 1];
        $this->getJson('/orders/list?'.http_build_query($query))
            ->assertOk()->assertJsonPath('total', 2)->assertJsonPath('data.0.id', $last->id);
        $this->getJson('/orders/list?'.http_build_query($query + ['page' => 2]))
            ->assertOk()->assertJsonPath('total', 2)->assertJsonPath('data.0.id', $first->id);
    }

    public function test_five_digits_find_every_matching_order_across_all_number_fields(): void
    {
        $byTtn = $this->order();
        $byTtn->delivery()->create(['delivery_type' => 'warehouse', 'ttn' => '20451234567890']);
        $byPhone = $this->order(Customer::create(['phone' => '+48 (12) 345-67-89', 'phone_normalized' => '48123456789']));
        $byRecipient = $this->order();
        $byRecipient->delivery()->create(['delivery_type' => 'warehouse', 'recipient_phone' => '12 345']);
        $byNumber = $this->order(null, ['order_number' => 'DOM-12345']);
        $byBoth = $this->order(Customer::create(['phone' => '1234567']));
        $byBoth->delivery()->create([
            'delivery_type' => 'warehouse', 'ttn' => '20451234500000',
        ]);
        $this->order(Customer::create(['phone' => '+380991234999', 'phone_normalized' => '380991234999']));

        // Об'єднання збігів з усіх полів без дублів, навіть якщо збігів у замовленні кілька.
        $this->assertSearchReturns('12345', [$byBoth->id, $byNumber->id, $byRecipient->id, $byPhone->id, $byTtn->id]);
        $this->assertSearchReturns('12 345', [$byBoth->id, $byNumber->id, $byRecipient->id, $byPhone->id, $byTtn->id]);
    }

    public function test_any_length_of_digits_is_a_substring_even_when_it_looks_like_a_phone(): void
    {
        $order = $this->order(Customer::create([
            'phone' => '+48 380 99 111 22 33 77', 'phone_normalized' => '4838099111223377',
        ]));
        $byTtn = $this->order();
        $byTtn->delivery()->create(['delivery_type' => 'warehouse', 'ttn' => '0038099111223377']);

        foreach (['380991112233', '0991112233', '99111223', '77', '0'] as $search) {
            $this->assertSearchReturns($search, [$byTtn->id, $order->id]);
        }
    }

    #[DataProvider('nameSearches')]
    public function test_searches_names_by_each_word_in_any_order_and_case(string $search, array $matches): void
    {
        $this->order(Customer::create(['first_name' => 'Зоряна', 'last_name' => 'Гордієнко']));
        $this->order(Customer::create(['first_name' => 'Зоряна', 'last_name' => 'Бондар']));
        $this->order(Customer::create(['first_name' => 'Олег', 'last_name' => 'Гордієнко']));
        $this->order(Customer::create(['first_name' => 'ЗОРЯНА ГОРДІЄНКО']));
        $recipient = $this->order();
        $recipient->delivery()->create(['delivery_type' => 'warehouse', 'recipient_name' => 'Гордієнко Зоряна']);

        $this->assertSearchReturns($search, $matches);
    }

    public static function nameSearches(): array
    {
        return [
            ['Зоряна', [5, 4, 2, 1]], ['зоряна', [5, 4, 2, 1]], ['ЗОРЯНА', [5, 4, 2, 1]],
            ['Гордієнко', [5, 4, 3, 1]], ['гордієнко', [5, 4, 3, 1]],
            ['Гордієнко Зоряна', [5, 4, 1]], ['Зоряна Гордієнко', [5, 4, 1]],
            ["  зоряна\u{00A0}\t\u{202F}ГОРДІЄНКО  ", [5, 4, 1]], ['гордієн зорян', [5, 4, 1]],
            ['Зоряна Незнайомий', []],
        ];
    }

    public function test_searches_mixed_text_and_digits_as_required_parts_of_the_same_order(): void
    {
        $customer = Customer::create(['first_name' => 'Зоряна', 'last_name' => 'Гордієнко']);
        $order = $this->order($customer);
        $order->delivery()->create(['delivery_type' => 'warehouse', 'ttn' => '20451234567890']);
        $this->order($customer);
        $this->order()->delivery()->create(['delivery_type' => 'warehouse', 'ttn' => '20451234567890']);

        $this->assertSearchReturns('Гордієнко 12345 Зоряна', [$order->id]);
        $this->assertSearchReturns('12345 Зоряна Гордієнко', [$order->id]);
    }

    public function test_current_contacts_and_ttn_are_used_instead_of_stale_search_blob(): void
    {
        $order = $this->order(Customer::create(['first_name' => 'Зоряна', 'last_name' => 'Гордієнко']), [
            'search_blob' => 'Старий Контакт 0678889900 20458888888888',
        ]);
        $order->delivery()->create(['delivery_type' => 'warehouse', 'ttn' => '20451234567890']);

        $this->assertSearchReturns('Гордієнко Зоряна', [$order->id]);
        $this->assertSearchReturns('Старий', []);
        $this->assertSearchReturns('88888', []);
    }

    public function test_searches_actual_phone_when_normalized_value_is_stale(): void
    {
        $order = $this->order(Customer::create(['phone' => '+48 123-45-67', 'phone_normalized' => '380991112233']));

        $this->assertSearchReturns('12345', [$order->id]);
    }

    public function test_blank_query_returns_all_orders_and_special_characters_are_literal(): void
    {
        $first = $this->order(null, ['order_number' => 'DOM_100%']);
        $second = $this->order(null, ['order_number' => 'DOM!200']);

        $this->assertSearchReturns(" \u{00A0}\t\n ", [$second->id, $first->id]);
        $this->assertSearchReturns('%', [$first->id]);
        $this->assertSearchReturns('_', [$first->id]);
        $this->assertSearchReturns('!', [$second->id]);
    }

    public function test_rejects_invalid_search_without_a_server_error(): void
    {
        foreach ([['search' => ['12345']], ['search' => str_repeat('а', 201)]] as $query) {
            $this->getJson('/orders/list?'.http_build_query($query))
                ->assertUnprocessable()->assertJsonValidationErrors('search');
        }
    }

    public function test_accepts_a_unicode_query_at_the_length_limit_and_treats_pattern_characters_literally(): void
    {
        $name = mb_substr(str_repeat('АБВГҐДЕЄЖЗИІЇЙКЛМНОПРСТУФХЦЧШЩЬЮЯ', 7), 0, 200);
        $order = $this->order(Customer::create(['first_name' => $name]));
        $special = $this->order(Customer::create(['first_name' => 'Зоряна [тест]*?']));

        $this->assertSearchReturns(mb_strtolower($name), [$order->id]);
        foreach (['[', ']', '*', '?', '[тест]*?'] as $search) {
            $this->assertSearchReturns($search, [$special->id]);
        }
    }

    private function order(?Customer $customer = null, array $attributes = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'SEARCH-'.(Order::count() + 1),
            'customer_id' => $customer?->id,
            'status' => 'new', 'payment_status' => 'unpaid', 'currency' => 'UAH',
        ], $attributes));
    }

    private function assertSearchReturns(string $search, array $ids): void
    {
        $response = $this->getJson('/orders/list?'.http_build_query(['search' => $search]))->assertOk();
        $this->assertSame($ids, array_column($response->json('data'), 'id'), $search);
    }
}
