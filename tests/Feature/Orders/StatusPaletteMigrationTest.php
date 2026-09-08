<?php

namespace Tests\Feature\Orders;

use Database\Seeders\StatusesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatusPaletteMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_changes_only_known_colors_and_preserves_business_data(): void
    {
        $this->seed(StatusesSeeder::class);
        DB::table('statuses')->where('code', 'delivered')->update(['color' => '#4ade80']);
        $cases = [
            ['created', 'Створена накладна', 'bi-file-earmark', '#37caec', '#78716c'],
            ['deleted', 'Видалено', 'bi-trash', '#8d9fa3', '#1f2937'],
            ['in_transit', 'У місті відправника', 'bi-truck', '#2563eb', '#0ea5e9'],
            ['in_transit', 'Прямує до міста одержувача', 'bi-truck', '#2563eb', '#0ea5e9'],
            ['in_transit', 'У місті одержувача', 'bi-truck', '#2563eb', '#0ea5e9'],
            ['unknown', 'Номер не знайдено', 'bi-question-circle', '#6b7280', '#f59e0b'],
            ['created', 'Створена накладна', 'bi-file-earmark', '#123456', '#123456'],
            ['created', 'Створена накладна', 'bi-file-earmark', '#78716c', '#78716c'],
            ['created', 'Власна назва', 'bi-file-earmark', '#37caec', '#37caec'],
            ['created', 'Створена накладна', 'bi-star', '#37caec', '#37caec'],
            ['unknown', 'Інша помилка', 'bi-question-circle', '#6b7280', '#6b7280'],
            ['in_transit', 'Змінено адресу доставки', 'bi-geo-alt', '#f59e0b', '#f59e0b'],
            ['cod_on_way', 'Отримано, гроші в дорозі', 'bi-cash-coin', '#16a34a', '#16a34a'],
            ['received', 'Отримано', 'bi-check-circle-fill', '#16a34a', '#16a34a'],
            ['created', 'Створена накладна', 'bi-file-earmark', '#37caec', '#37caec', 'inpost'],
        ];
        $expected = [];

        foreach ($cases as $index => $case) {
            [$code, $label, $icon, $before, $after] = $case;
            $orderId = DB::table('orders')->insertGetId([
                'order_number' => 'PALETTE-'.$index,
                'status' => 'delivered',
                'status_id' => DB::table('statuses')->where('code', 'delivered')->value('id'),
                'status_changed_at' => '2026-09-07 08:00:00',
                'created_at' => '2026-09-01 10:00:00',
                'updated_at' => '2026-09-07 11:00:00',
            ]);
            $deliveryId = DB::table('order_deliveries')->insertGetId([
                'order_id' => $orderId,
                'carrier' => $case[5] ?? 'nova_poshta',
                'delivery_type' => 'warehouse',
                'delivery_status_code' => $code,
                'delivery_status_label' => $label,
                'delivery_status_description' => 'Попередній опис',
                'delivery_status_icon' => $icon,
                'delivery_status_color' => $before,
                'delivery_status_updated_at' => '2026-09-07 10:00:00',
                'last_tracked_at' => '2026-09-07 11:00:00',
                'created_at' => '2026-09-01 10:00:00',
                'updated_at' => '2026-09-07 11:00:00',
            ]);
            DB::table('order_delivery_status_histories')->insert([
                'order_delivery_id' => $deliveryId,
                'status_code' => $code,
                'status_label' => $label,
                // Навіть стара історія з кодом 3 не має фарбувати довільний unknown.
                'source_code' => $code === 'unknown' ? '3' : null,
                'entered_at' => '2026-09-07 08:00:00',
                'created_at' => '2026-09-07 08:00:00',
                'updated_at' => '2026-09-07 08:00:00',
            ]);
            DB::table('order_status_changes')->insert([
                'order_id' => $orderId,
                'order_number' => 'PALETTE-'.$index,
                'new_status' => 'delivered',
                'source' => 'system',
                'reason' => 'Попередня зміна статусу',
                'occurred_at' => '2026-09-07 08:00:00',
            ]);
            $expected[$deliveryId] = $after;
        }

        $before = $this->snapshot();
        $migration = require database_path('migrations/2026_09_08_000002_align_order_and_delivery_status_colors.php');
        $migration->up();

        foreach ($before['statuses'] as &$status) {
            if ($status['code'] === 'delivered') {
                $status['color'] = '#f59e0b';
            }
        }
        unset($status);
        foreach ($before['order_deliveries'] as &$delivery) {
            $delivery['delivery_status_color'] = $expected[$delivery['id']];
        }
        unset($delivery);
        $this->assertSame($before, $this->snapshot());

        // Повторний запуск і відкат не мають перезаписувати дані чи часові мітки.
        $migration->up();
        $migration->down();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_custom_order_color_is_preserved(): void
    {
        $this->seed(StatusesSeeder::class);
        DB::table('statuses')->where('code', 'delivered')->update(['color' => '#123456']);
        $before = $this->snapshot();
        $migration = require database_path('migrations/2026_09_08_000002_align_order_and_delivery_status_colors.php');

        $migration->up();
        $migration->down();

        $this->assertSame($before, $this->snapshot());
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['statuses', 'orders', 'order_deliveries', 'order_status_changes', 'order_delivery_status_histories'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()
                ->map(fn ($row) => (array) $row)->all();
        }

        return $result;
    }
}
