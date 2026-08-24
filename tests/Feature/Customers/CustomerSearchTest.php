<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        $this->actingAs($this->operator());
        DB::enableQueryLog();

        $this->getJson('/customers?q=0991112233') // інший формат, той самий номер
            ->assertOk()
            ->assertJsonFragment(['phone' => '+380991112233']);

        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertTrue($queries->contains(fn (string $sql) => str_contains($sql, '"phone_normalized" = ?')));
        $this->assertFalse($queries->contains(fn (string $sql) => str_contains($sql, '"phone_normalized" like ?')));
    }

    public function test_finds_customer_by_partial_phone_with_fallback_search(): void
    {
        Customer::create([
            'first_name' => 'Іван', 'phone' => '+380991112233', 'phone_normalized' => '380991112233',
        ]);

        $this->actingAs($this->operator())
            ->getJson('/customers?q=1112')
            ->assertOk()
            ->assertJsonFragment(['phone' => '+380991112233']);
    }

    public function test_updating_phone_refreshes_normalized_value_without_erasing_other_contacts(): void
    {
        $customer = Customer::create([
            'first_name' => 'Іван',
            'last_name' => 'Петренко',
            'phone' => '+380991112233',
            'phone_normalized' => '380991112233',
            'email' => 'ivan@example.test',
        ]);

        $this->actingAs($this->operator())
            ->putJson("/api/customers/{$customer->id}", ['phone' => '067 222 33 44'])
            ->assertOk();

        $customer->refresh();

        $this->assertSame('0672223344', $customer->phone);
        $this->assertSame('380672223344', $customer->phone_normalized);
        $this->assertSame('Іван', $customer->first_name);
        $this->assertSame('Петренко', $customer->last_name);
        $this->assertSame('ivan@example.test', $customer->email);
    }
}
