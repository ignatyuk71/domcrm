<?php

namespace Tests\Feature;

use App\Models\Status;
use Database\Seeders\StatusesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Запобіжник проти розсинхрону довідника статусів із продом.
 *
 * На цих кодах зав'язані автоматизації (НП-трекінг, фіскалізація, пакування),
 * тому набір має лишатися стабільним. Якщо сидер «попливе» — тест впаде.
 */
class StatusesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_exact_prod_status_set(): void
    {
        $this->seed(StatusesSeeder::class);

        $expected = [
            'new'            => 'Новий',
            'in_process'     => 'В обробці',
            'confirmed'      => 'Підтверджено',
            'packing'        => 'Упакування',
            'packed'         => 'Запаковано',
            'shipped'        => 'Відправлено',
            'delivered'      => 'У відділенні',
            'delivered_paid' => 'Завершено',
            'returned'       => 'Повернення',
            'cancelled'      => 'Скасовано',
        ];

        $actual = Status::where('type', 'order')->orderBy('sort_order')->pluck('name', 'code')->all();

        $this->assertSame($expected, $actual, 'Набір/назви статусів розійшлися з продом');
    }

    public function test_codes_required_by_automations_exist(): void
    {
        $this->seed(StatusesSeeder::class);

        // Коди, на яких тримаються критичні автоматизації.
        foreach (['delivered_paid', 'delivered', 'packing', 'packed', 'returned', 'new'] as $code) {
            $this->assertDatabaseHas('statuses', ['type' => 'order', 'code' => $code]);
        }
    }

    public function test_new_is_the_single_default_status(): void
    {
        $this->seed(StatusesSeeder::class);

        $defaults = Status::where('type', 'order')->where('is_default', true)->pluck('code')->all();

        $this->assertSame(['new'], $defaults);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(StatusesSeeder::class);
        $this->seed(StatusesSeeder::class);

        $this->assertSame(10, Status::where('type', 'order')->count(), 'Повторний сід дублює рядки');
    }
}