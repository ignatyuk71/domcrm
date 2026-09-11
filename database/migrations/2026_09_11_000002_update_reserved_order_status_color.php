<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Відокремлюємо бронювання від фіолетового статусу підтвердження.
        DB::table('statuses')
            ->where('type', 'order')
            ->where('code', 'reserved')
            ->update(['color' => '#0f766e', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Зберігаємо колір, якщо його згодом змінили вручну.
        DB::table('statuses')
            ->where('type', 'order')
            ->where('code', 'reserved')
            ->where('color', '#0f766e')
            ->update(['color' => '#7c3aed', 'updated_at' => now()]);
    }
};
