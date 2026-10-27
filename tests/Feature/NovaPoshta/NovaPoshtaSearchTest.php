<?php

namespace Tests\Feature\NovaPoshta;

use App\Models\NovaPoshtaSetting;
use App\Models\User;
use App\Services\NovaPoshtaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Пошук НП: при відсутньому ключі/збої повертаємо зрозумілу помилку, а не
 * мовчазний порожній список. Happy-path не змінений.
 */
class NovaPoshtaSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => User::ROLE_OWNER, 'is_active' => true]);
    }

    public function test_cities_returns_error_when_not_configured(): void
    {
        config(['services.nova_poshta.api_key' => null, 'services.novaposhta.key' => null]);
        // NovaPoshtaSetting не створюємо → ключа немає

        $this->actingAs($this->owner())
            ->getJson('/nova-poshta/cities?q=Київ')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('error', 'Нова Пошта не налаштована');

        Http::assertNothingSent();
    }

    public function test_search_returns_data_when_configured(): void
    {
        NovaPoshtaSetting::create(['api_key' => 'TEST-NP-KEY']);

        Http::fake([
            'https://api2.novaposhta.ua/v2.0/json/' => Http::response([
                'success' => true,
                'data' => [[
                    'Addresses' => [[
                        'Present' => 'Київ, Київська обл.',
                        'DeliveryCity' => 'city-ref-1',
                        'SettlementRef' => 'settlement-ref-1',
                        'Area' => 'Київська',
                    ]],
                ]],
            ], 200),
        ]);

        $cities = app(NovaPoshtaService::class)->searchCities('Київ');

        $this->assertCount(1, $cities);
        $this->assertSame('Київ, Київська обл.', $cities[0]['name']);
        Http::assertSentCount(1);
    }

    public function test_search_retries_on_transient_5xx(): void
    {
        NovaPoshtaSetting::create(['api_key' => 'TEST-NP-KEY']);

        // Перший запит — 500 (транзієнтний), другий — успіх.
        Http::fakeSequence('https://api2.novaposhta.ua/v2.0/json/')
            ->push('server error', 500)
            ->push([
                'success' => true,
                'data' => [['Addresses' => [[
                    'Present' => 'Київ',
                    'DeliveryCity' => 'c1',
                    'SettlementRef' => 's1',
                    'Area' => 'Київська',
                ]]]],
            ], 200);

        $cities = app(NovaPoshtaService::class)->searchCities('Київ');

        $this->assertCount(1, $cities); // після повтору отримали дані
        Http::assertSentCount(2);       // 500 → повтор → успіх
    }

    public function test_search_recovers_after_a_connection_timeout_on_api2(): void
    {
        NovaPoshtaSetting::create(['api_key' => 'TEST-NP-KEY']);
        Http::fakeSequence('https://api2.novaposhta.ua/v2.0/json/')
            ->pushFailedConnection('Connection timeout')
            ->push([
                'success' => true,
                'data' => [['Addresses' => [[
                    'Present' => 'Київ',
                    'DeliveryCity' => 'c1',
                    'SettlementRef' => 's1',
                ]]]],
            ]);

        $cities = app(NovaPoshtaService::class)->searchCities('Київ');

        $this->assertCount(1, $cities);
        $this->assertSame('c1', $cities[0]['delivery_city_ref']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api2.novaposhta.ua/v2.0/json/');
    }

    public function test_search_stops_after_two_retries_when_api2_keeps_failing(): void
    {
        NovaPoshtaSetting::create(['api_key' => 'TEST-NP-KEY']);
        Http::fake([
            'https://api2.novaposhta.ua/v2.0/json/' => Http::response(['success' => false], 503),
        ]);

        $this->assertSame([], app(NovaPoshtaService::class)->searchCities('Київ'));

        Http::assertSentCount(3);
    }
}
