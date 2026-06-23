<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DashboardDataTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => User::ROLE_OWNER, 'is_active' => true]);
    }

    public function test_returns_structure_and_caches_aggregates(): void
    {
        $this->actingAs($this->owner())
            ->getJson('/api/dashboard/data?days=30')
            ->assertOk()
            ->assertJsonStructure(['days', 'series', 'kpis', 'recent_orders', 'top_products', 'source_breakdown', 'losses', 'generated_at']);

        // Важкі агрегації осіли в кеші на 5 хв.
        $this->assertTrue(Cache::has('dashboard:data:30'));
    }
}
