<?php

namespace Tests\Feature\NovaPoshta;

use App\Models\NovaPoshtaSetting;
use App\Models\User;
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
    }

    public function test_search_returns_data_when_configured(): void
    {
        NovaPoshtaSetting::create(['api_key' => 'TEST-NP-KEY']);

        Http::fake([
            '*api.novaposhta.ua*' => Http::response([
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

        $cities = app(\App\Services\NovaPoshtaService::class)->searchCities('Київ');

        $this->assertCount(1, $cities);
        $this->assertSame('Київ, Київська обл.', $cities[0]['name']);
    }
}
