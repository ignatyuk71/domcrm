<?php

namespace Tests\Feature\Orders;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Status;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\TestCase;

class OrderSearchCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_OPERATOR, 'is_active' => true]));
    }

    public function test_generated_numeric_fragments_return_the_complete_matching_set(): void
    {
        $random = new Randomizer(new Mt19937(867203));
        $records = [];
        $queries = [];

        for ($i = 0; $i < 24; $i++) {
            $number = (string) $random->getInt(10000, 99999999);
            $phone = ($i % 2 ? '44' : '380').$random->getInt(100000000, 999999999);
            $recipientPhone = str_pad((string) $random->getInt(0, 99999999), 8, '0', STR_PAD_LEFT);
            $ttn = '2'.$random->getInt(1000000000000, 9999999999999);
            $order = $this->order([
                'phone' => '+'.implode(' ', str_split($phone, 3)),
                // Неправильний старий індекс не має впливати на результат.
                'phone_normalized' => $i % 3 ? null : '777777777777',
            ], ['ttn' => implode('-', str_split($ttn, 4)), 'recipient_phone' => implode('.', str_split($recipientPhone, 2))], [
                'order_number' => 'WEB-'.$number,
            ]);
            $fields = [$number, $phone, $recipientPhone, $ttn];
            $records[$order->id] = $fields;

            foreach ($fields as $field) {
                foreach ([1, 2, 4, 5, 8, 9, 10, 12, 14] as $length) {
                    if ($length <= strlen($field)) {
                        $start = $random->getInt(0, strlen($field) - $length);
                        $queries[] = substr($field, $start, $length);
                    }
                }
            }
        }

        $queries = array_values(array_unique([...$queries, '000000000000000000', '777777777777']));
        foreach ($queries as $index => $query) {
            // Незалежний критерій: безперервний фрагмент фактичних цифр у будь-якому числовому полі.
            $expected = array_keys(array_filter($records, fn (array $fields) => (bool) array_filter($fields, fn (string $field) => str_contains($field, $query))));
            rsort($expected);
            $this->assertMatches($query, $expected);

            if ($index % 17 === 0) {
                $this->assertMatches(implode("\u{00A0}", str_split($query, 2)), $expected);
            }
        }
    }

    public function test_generated_names_match_word_fragments_permutations_and_typography(): void
    {
        $givenNames = ['Наталія', 'Данило', 'Тамара', 'Левко', "Мар'яна", 'Богдан'];
        $familyNames = ['Савчук', 'Остренко', 'Гордієнко', "Д'яченко", 'Соловей-Руденко', 'Мельник'];
        $records = [];
        $queries = [];

        for ($i = 0; $i < 24; $i++) {
            $first = $givenNames[$i % 6];
            $last = $familyNames[intdiv($i, 4)];
            $displayFirst = str_replace("'", "\u{2019}", $first);
            $displayLast = strtr($last, ["'" => "\u{02BC}", '-' => "\u{2011}"]);
            $order = $this->order([
                'first_name' => $i % 2 ? mb_strtoupper($displayFirst) : $displayFirst,
                'last_name' => $displayLast,
            ]);
            $records[$order->id] = mb_strtolower($first.' '.$last);
            $queries[] = [$first];
            $queries[] = [$last];
            $queries[] = [mb_substr($first, 0, 3), mb_substr($last, -4)];
            $queries[] = [$last, $first];
        }

        foreach ($queries as $index => $words) {
            // Очікування визначаємо за вихідними іменами до форматування й запису в БД.
            $expected = array_keys(array_filter($records, fn (string $name) => count(array_filter($words, fn (string $word) => str_contains($name, mb_strtolower($word)))) === count($words)));
            rsort($expected);
            $this->assertMatches(implode(' ', $words), $expected);
            if ($index % 7 === 0) {
                $this->assertMatches('  '.mb_strtoupper(implode("\t\u{202F}", array_reverse($words))).'  ', $expected);
            }
        }
        $this->assertMatches('Невідоме прізвище', []);
    }

    public function test_phone_fragments_have_the_same_meaning_across_domestic_formats(): void
    {
        $canonical = '380734719058';
        $records = [];
        $domesticIds = [];
        foreach (['734719058', '0734719058', '80734719058', $canonical, '00380734719058'] as $digits) {
            $order = $this->order(['phone' => implode(' ', str_split($digits, 2))]);
            $records[$order->id] = [$digits, $canonical];
            $domesticIds[] = $order->id;
        }
        foreach (['44734719058', '49734719058', '380956283407'] as $digits) {
            $order = $this->order(['phone' => '+'.$digits]);
            $records[$order->id] = [$digits];
        }

        $queries = [];
        for ($length = 1; $length <= strlen($canonical); $length++) {
            for ($start = 0; $start + $length <= strlen($canonical); $start++) {
                $queries[] = substr($canonical, $start, $length);
            }
        }
        foreach (array_unique($queries) as $query) {
            $expected = array_keys(array_filter($records, fn (array $fields) => (bool) array_filter($fields, fn (string $field) => str_contains($field, $query))));
            rsort($expected);
            $this->assertMatches($query, $expected);
        }
        $this->assertMatches('00380734719058', array_reverse($domesticIds));
    }

    public function test_full_phone_does_not_match_another_country_by_only_the_suffix(): void
    {
        $domestic = $this->order(['phone' => '068 507 96 24']);
        $foreign = $this->order(['phone' => '+44 685079624', 'phone_normalized' => '44685079624']);
        $recipient = $this->order([], ['recipient_phone' => '+49 685079624']);

        $this->assertMatches('+380685079624', [$domestic->id]);
        // Звичайний фрагмент усе одно має знаходити номери будь-якої країни.
        $this->assertMatches('50796', [$recipient->id, $foreign->id, $domestic->id]);
    }

    public function test_stale_normalized_phone_does_not_return_the_previous_contact(): void
    {
        $order = $this->order(['phone' => '+48 735 204 186', 'phone_normalized' => '380685079624']);

        $this->assertMatches('20418', [$order->id]);
        $this->assertMatches('50796', []);
        $this->assertMatches('+380685079624', []);
        $order->customer->update(['phone' => null]);
        $this->assertMatches('50796', []);
    }

    public function test_invalid_phone_placeholders_are_not_interpreted_as_domestic_numbers(): void
    {
        foreach (['невказано', 'abcdefghi', '0abcdefgh', '000abc12x'] as $placeholder) {
            $this->order(['phone' => $placeholder]);
        }
        $this->assertMatches('380', []);
        $this->assertMatches('80', []);
    }

    public function test_mixed_queries_keep_formatted_number_fragments_together(): void
    {
        $match = $this->order(['first_name' => 'Наталія', 'last_name' => 'Савчук', 'phone' => '+380 (68) 507-96-24']);
        $this->order(['first_name' => 'Наталія', 'last_name' => 'Савчук', 'phone' => '+380 (68) 507-11-24'], [
            'ttn' => '96000000000000',
        ]);
        $this->order(['first_name' => 'Наталія', 'last_name' => 'Савчук', 'phone' => '+380 73 000 00 00'], [
            'ttn' => '20685079624000',
        ]);

        // Розділювачі не ділять число на незалежні умови для різних полів.
        $this->assertMatches('Савчук +380 (68) 507-96-24', [$match->id]);
        $this->assertMatches('+380 (68) 507-96-24 Наталія', [$match->id]);
        $this->assertMatches('Наталія 507 96 24', [3, $match->id]);
    }

    public function test_copied_formatting_and_order_number_prefixes_are_supported(): void
    {
        $order = $this->order(['first_name' => "Мар\u{2019}яна", 'last_name' => "Соловей\u{2011}Руденко", 'phone' => "\u{200E}+380 68 507 96 24\u{200B}"], [], [
            'order_number' => 'WEB-849206',
        ]);

        foreach (["Мар'яна Соловей-Руденко", "Мар\u{02BC}яна", "\u{200E}+380685079624\u{200F}",
            '#WEB-849206', '№ WEB-849206', 'Руденко № WEB-849206'] as $query) {
            $this->assertMatches($query, [$order->id]);
        }
    }

    public function test_results_remain_complete_and_unique_across_pages_and_combined_filters(): void
    {
        $status = Status::create(['code' => 'coverage', 'name' => 'Перевірка', 'type' => 'order']);
        $tag = Tag::create(['code' => 'coverage', 'name' => 'Пріоритет']);
        $ids = [];
        for ($i = 0; $i < 23; $i++) {
            $order = $this->order(['last_name' => 'Лисенко', 'phone' => '073 849 20 61'], ['ttn' => '20849206170000'], [
                'status_id' => $status->id, 'payment_status' => 'paid',
            ]);
            $order->tags()->attach($tag);
            $ids[] = $order->id;
        }
        $this->order(['last_name' => 'Лисенко', 'phone' => '073 849 20 61'], [], ['payment_status' => 'unpaid']);

        $returned = [];
        for ($page = 1; $page <= 4; $page++) {
            $data = $this->getJson('/orders/list?'.http_build_query([
                'search' => 'Лисенко 84920', 'status_ids' => $status->id, 'payment_status' => 'paid',
                'tag_ids' => [$tag->id], 'per_page' => 7, 'page' => $page,
            ]))->assertOk()->assertJsonPath('total', 23)->assertJsonPath('last_page', 4)->json('data');
            array_push($returned, ...array_column($data, 'id'));
        }
        $this->assertSame(array_reverse($ids), $returned);
    }

    public function test_missing_relations_and_legacy_data_do_not_break_literal_queries(): void
    {
        $this->order();
        $legacy = $this->order([], [], ['search_blob' => 'Архівна Покупчиня 0584201369']);
        $legacy->customer()->dissociate()->save();
        $special = $this->order(['email' => 'buyer+promo@example.test'], [], ['order_number' => "O'R_70%!\\batch"]);

        $this->assertMatches('Покупчиня Архівна', [$legacy->id]);
        $this->assertMatches('buyer+promo@example.test', [$special->id]);
        foreach (["'R", '_', '%', '!', '\\batch'] as $literal) {
            $this->assertMatches($literal, [$special->id]);
        }
        $this->assertMatches("' OR 1=1 --", []);
    }

    public function test_search_never_bypasses_role_or_account_restrictions(): void
    {
        $this->order(['last_name' => 'Пошуковий запис']);
        foreach ([[User::ROLE_PACKER, true], [User::ROLE_OPERATOR, false]] as [$role, $active]) {
            $this->actingAs(User::factory()->create(['role' => $role, 'is_active' => $active]))
                ->getJson('/orders/list?search='.urlencode('Пошуковий'))->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->getJson('/orders/list?search='.urlencode('Пошуковий'))->assertUnauthorized();
    }

    private function order(array $customer = [], array $delivery = [], array $attributes = []): Order
    {
        $buyer = Customer::create($customer);
        $order = Order::create(array_replace([
            'order_number' => 'COVER-'.(Order::count() + 1), 'customer_id' => $buyer->id,
            'status' => 'new', 'payment_status' => 'unpaid', 'currency' => 'UAH',
        ], $attributes));
        if ($delivery) {
            $order->delivery()->create(array_replace(['delivery_type' => 'warehouse'], $delivery));
        }

        return $order;
    }

    private function assertMatches(string $query, array $ids): void
    {
        $response = $this->getJson('/orders/list?'.http_build_query(['search' => $query, 'per_page' => 100]))->assertOk();
        $this->assertSame($ids, array_column($response->json('data'), 'id'), 'Запит: '.$query);
        $this->assertSame(count($ids), $response->json('total'), 'Кількість: '.$query);
    }
}
