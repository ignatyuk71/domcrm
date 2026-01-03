<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class StatusesSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();
        $rows = [
            ['code' => 'pending',    'name' => 'Очікує підтвердження', 'icon' => 'bi-hourglass-split', 'color' => '#fcd5b5', 'sort_order' => 10],
            ['code' => 'in_process', 'name' => 'В обробці',           'icon' => 'bi-gear-wide-connected', 'color' => '#c7f2d4', 'sort_order' => 20],
            ['code' => 'confirmed',  'name' => 'Підтверджене',        'icon' => 'bi-check2-circle',   'color' => '#b2ecf7', 'sort_order' => 30],
            ['code' => 'packed',     'name' => 'Упаковане',           'icon' => 'bi-box-seam',        'color' => '#e4e6e9', 'sort_order' => 40],
            ['code' => 'shipped',    'name' => 'Відправлене',         'icon' => 'bi-truck',           'color' => '#dcd9ff', 'sort_order' => 50],
            ['code' => 'delivered',  'name' => 'Доставлене',          'icon' => 'bi-bag-check',       'color' => '#c6f1d4', 'sort_order' => 60],
            ['code' => 'cancelled',  'name' => 'Скасоване',           'icon' => 'bi-x-circle',        'color' => '#e4e6e9', 'sort_order' => 70],
            ['code' => 'returned',   'name' => 'Повернене',           'icon' => 'bi-arrow-counterclockwise', 'color' => '#ffd6d6', 'sort_order' => 80],
        ];

        $payload = array_map(function ($row) use ($now) {
            return array_merge([
                'type' => 'order',
                'is_default' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ], $row);
        }, $rows);

        DB::table('statuses')->upsert(
            $payload,
            ['code', 'type'],
            ['name', 'icon', 'color', 'sort_order', 'is_default', 'updated_at']
        );
    }
}
