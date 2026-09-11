<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Додаємо окремий статус, не змінюючи «Підтверджено» та наявні замовлення.
        DB::table('statuses')->insertOrIgnore([
            'code' => 'reserved',
            'name' => 'Бронювання',
            'type' => 'order',
            'icon' => 'bi-calendar2-check',
            'color' => '#7c3aed',
            'sort_order' => 35,
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Зберігаємо довідник при відкаті: статус уже може використовуватися замовленнями та історією.
    }
};
