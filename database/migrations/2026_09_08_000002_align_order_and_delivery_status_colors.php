<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            // Змінюємо лише старий стандартний колір; ручні налаштування зберігаємо.
            DB::table('statuses')
                ->where('type', 'order')
                ->where('code', 'delivered')
                ->where('color', '#4ade80')
                ->update(['color' => '#f59e0b']);

            $changes = [
                ['created', 'Створена накладна', 'bi-file-earmark', '#37caec', '#78716c'],
                ['deleted', 'Видалено', 'bi-trash', '#8d9fa3', '#1f2937'],
                ['in_transit', 'У місті відправника', 'bi-truck', '#2563eb', '#0ea5e9'],
                ['in_transit', 'Прямує до міста одержувача', 'bi-truck', '#2563eb', '#0ea5e9'],
                ['in_transit', 'У місті одержувача', 'bi-truck', '#2563eb', '#0ea5e9'],
                ['unknown', 'Номер не знайдено', 'bi-question-circle', '#6b7280', '#f59e0b'],
            ];

            foreach ($changes as [$code, $label, $icon, $before, $after]) {
                // Код джерела в історії може застаріти: звіряємо поточні поля доставки.
                // Query Builder змінює лише колір, без часових міток та подій моделей.
                DB::table('order_deliveries')
                    ->where('carrier', 'nova_poshta')
                    ->where('delivery_status_code', $code)
                    ->where('delivery_status_label', $label)
                    ->where('delivery_status_icon', $icon)
                    ->where('delivery_status_color', $before)
                    ->update(['delivery_status_color' => $after]);
            }
        });
    }

    public function down(): void
    {
        // Без знімка попередніх значень неможливо відрізнити нову палітру від
        // ручних налаштувань. Відкат коду залишає кольори та бізнес-дані цілими.
    }
};
