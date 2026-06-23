<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerSearchTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        return User::factory()->create(['role' => User::ROLE_OPERATOR, 'is_active' => true]);
    }

    /** Пошук знаходить клієнта за нормалізованим номером (інший формат вводу). */
    public function test_finds_customer_by_normalized_phone(): void
    {
        Customer::create([
            'first_name' => 'Іван', 'phone' => '+380991112233', 'phone_normalized' => '380991112233',
        ]);

        $this->actingAs($this->operator())
            ->getJson('/customers?q=0991112233') // інший формат, той самий номер
            ->assertOk()
            ->assertJsonFragment(['phone' => '+380991112233']);
    }
}
