<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SoleInventoryService
{
    public function savePlan(int $categoryId, array $data, int $userId): void
    {
        DB::transaction(function () use ($categoryId, $data, $userId) {
            // Блокуємо батьківський рядок і під час першого налаштування, коли плану ще немає.
            DB::table('categories')->where('id', $categoryId)->lockForUpdate()->first();
            $previous = DB::table('sole_inventory_plans')->where('category_id', $categoryId)->first();
            abort_if((int) ($previous->version ?? 0) !== (int) $data['version'], 409, 'Налаштування вже змінилися. Оновіть сторінку перед збереженням.');

            if ($previous && $previous->opening_date !== $data['opening_date'] && DB::table('sole_inventory_movements')->where('plan_id', $previous->id)->exists()) {
                throw ValidationException::withMessages(['opening_date' => 'Після внесення рухів дату початкового залишку змінити не можна. Використайте коригування.']);
            }

            $values = [
                'category_id' => $categoryId,
                'opening_date' => $data['opening_date'],
                'opening_balances' => json_encode($data['opening_balances'], JSON_THROW_ON_ERROR),
                'lead_time_days' => $data['lead_time_days'],
                'safety_days' => $data['safety_days'],
                'lookback_days' => $data['lookback_days'],
                'version' => (int) $data['version'] + 1,
                'updated_at' => now(),
            ];
            if ($previous) {
                DB::table('sole_inventory_plans')->where('id', $previous->id)->update($values);
                $planId = $previous->id;
            } else {
                $planId = DB::table('sole_inventory_plans')->insertGetId($values + ['created_at' => now()]);
            }

            // Зміна базового залишку завжди має слід; надходження та коригування не перезаписуються.
            DB::table('sole_inventory_plan_revisions')->insert([
                'plan_id' => $planId, 'before' => $previous ? json_encode($previous, JSON_THROW_ON_ERROR) : null,
                'after' => json_encode($values, JSON_THROW_ON_ERROR), 'user_id' => $userId, 'created_at' => now(),
            ]);
        });
    }

    public function addMovement(int $categoryId, array $data, int $userId): int
    {
        return DB::transaction(function () use ($categoryId, $data, $userId) {
            DB::table('categories')->where('id', $categoryId)->lockForUpdate()->first();
            $plan = DB::table('sole_inventory_plans')->where('category_id', $categoryId)->first();
            if (! $plan) {
                throw ValidationException::withMessages(['size' => 'Спочатку внесіть початковий залишок цієї категорії.']);
            }
            $existing = DB::table('sole_inventory_movements')->where('request_key', $data['request_key'])->first();
            if ($existing) {
                $same = (int) $existing->plan_id === (int) $plan->id;
                foreach (['size', 'kind', 'quantity', 'movement_date', 'note'] as $field) {
                    $same = $same && (string) $existing->$field === (string) ($data[$field] ?? null);
                }
                abort_unless($same, 409, 'Цей запит уже використаний для іншої операції. Оновіть сторінку.');

                return $existing->id;
            }

            $balance = collect(json_decode($plan->opening_balances, true))->firstWhere('size', $data['size']);
            if (($balance['quantity'] ?? null) === null) {
                throw ValidationException::withMessages(['size' => 'Спочатку внесіть початковий залишок цього розміру.']);
            }
            if ($data['movement_date'] < $plan->opening_date) {
                throw ValidationException::withMessages(['movement_date' => 'Дата руху не може бути ранішою за початковий залишок.']);
            }

            return DB::table('sole_inventory_movements')->insertGetId($data + ['plan_id' => $plan->id, 'user_id' => $userId, 'created_at' => now()]);
        });
    }
}
