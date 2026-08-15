<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class StatusesSeeder extends Seeder
{
    /**
     * Довідник статусів замовлення.
     *
     * ВАЖЛИВО: набір і коди мають 1:1 збігатися з продом, бо на них зав'язані
     * автоматизації: НП-трекінг (DeliveryStatusMapper), фіскалізація по
     * 'delivered_paid', екран пакування по 'packing'/'packed'. Якщо тут
     * розійтись із продом — на чистому середовищі/у тестах логіка поведеться
     * інакше (напр. fiscal:delivered не знайде 'delivered_paid' і піде у fallback).
     *
     * upsert по (code, type) — ідемпотентний, нічого не видаляє.
     */
    public function run(): void
    {
        $now = Carbon::now();
        $rows = [
            ['code' => 'new',            'name' => 'Новий',             'icon' => 'bi-circle',                 'color' => '#fbbf24', 'sort_order' => 10, 'is_default' => true],
            ['code' => 'in_process',     'name' => 'В обробці',         'icon' => 'bi-telephone',              'color' => '#3b82f6', 'sort_order' => 20, 'is_default' => false],
            ['code' => 'confirmed',      'name' => 'Підтверджено',      'icon' => 'bi-person-check-fill',      'color' => '#a855f7', 'sort_order' => 30, 'is_default' => false],
            ['code' => 'packing',        'name' => 'Упакування',        'icon' => 'bi-qr-code',                'color' => '#78716c', 'sort_order' => 40, 'is_default' => false],
            ['code' => 'packed',         'name' => 'Запаковано',        'icon' => 'bi-box-seam',               'color' => '#f97316', 'sort_order' => 45, 'is_default' => false],
            ['code' => 'shipped',        'name' => 'Відправлено',       'icon' => 'bi-truck',                  'color' => '#0ea5e9', 'sort_order' => 50, 'is_default' => false],
            ['code' => 'delivered',      'name' => 'У відділенні',      'icon' => 'bi-geo-alt',                'color' => '#4ade80', 'sort_order' => 60, 'is_default' => false],
            ['code' => 'delivered_paid', 'name' => 'Завершено',         'icon' => 'bi-check-all',              'color' => '#16a34a', 'sort_order' => 70, 'is_default' => false],
            ['code' => 'returned',       'name' => 'Повернення',        'icon' => 'bi-arrow-counterclockwise', 'color' => '#ef4444', 'sort_order' => 80, 'is_default' => false],
            ['code' => 'cancelled',      'name' => 'Скасовано',         'icon' => 'bi-x-circle',               'color' => '#1f2937', 'sort_order' => 90, 'is_default' => false],
        ];

        $payload = array_map(function ($row) use ($now) {
            return array_merge([
                'type' => 'order',
                'created_at' => $now,
                'updated_at' => $now,
            ], $row);
        }, $rows);

        // Конфлікт-таргет = реальний UNIQUE-ключ таблиці (code). На SQLite (тести)
        // ON CONFLICT мусить точно збігатися з констрейнтом, інакше QueryException.
        DB::table('statuses')->upsert(
            $payload,
            ['code'],
            ['name', 'icon', 'color', 'sort_order', 'is_default', 'updated_at']
        );
    }
}