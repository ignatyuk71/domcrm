<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RBAC: EnsureUserRole гейтить групи роутів за роллю + блокує неактивних.
 */
class RbacTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, bool $active = true): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => $active]);
    }

    public function test_packer_forbidden_from_orders(): void
    {
        $this->actingAs($this->user(User::ROLE_PACKER))
            ->getJson('/orders/list')
            ->assertStatus(403);
    }

    public function test_packer_forbidden_from_customers(): void
    {
        $this->actingAs($this->user(User::ROLE_PACKER))
            ->getJson('/customers?q=ів')
            ->assertStatus(403);
    }

    public function test_operator_forbidden_from_owner_settings(): void
    {
        $this->actingAs($this->user(User::ROLE_OPERATOR))
            ->getJson('/settings/team')
            ->assertStatus(403);
    }

    public function test_packer_allowed_in_packing(): void
    {
        $this->actingAs($this->user(User::ROLE_PACKER))
            ->getJson('/api/packing/list')
            ->assertStatus(200);
    }

    public function test_inactive_user_blocked(): void
    {
        $this->actingAs($this->user(User::ROLE_PACKER, false))
            ->getJson('/api/packing/list')
            ->assertStatus(403);
    }
}
