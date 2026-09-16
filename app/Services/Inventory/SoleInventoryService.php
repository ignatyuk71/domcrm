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

            $values = [
                'category_id' => $categoryId,
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
                $planId = DB::table('sole_inventory_plans')->insertGetId($values + [
                    'basis' => 'receipts', 'opening_date' => now()->toDateString(), 'opening_balances' => '[]', 'created_at' => now(),
                ]);
            }

            // Налаштування прогнозу не змінюють кількості отриманих партій.
            DB::table('sole_inventory_plan_revisions')->insert([
                'plan_id' => $planId, 'before' => $previous ? json_encode($previous, JSON_THROW_ON_ERROR) : null,
                'after' => json_encode($values, JSON_THROW_ON_ERROR), 'user_id' => $userId, 'created_at' => now(),
            ]);
        });
    }

    public function saveBatch(int $categoryId, array $data, int $userId, ?int $batchId = null): int
    {
        return DB::transaction(function () use ($categoryId, $data, $userId, $batchId) {
            DB::table('categories')->where('id', $categoryId)->lockForUpdate()->first();
            $plan = DB::table('sole_inventory_plans')->where('category_id', $categoryId)->first();
            $quantities = collect($data['quantities'])->map(fn ($row) => ['size' => $row['size'], 'quantity' => (int) $row['quantity']])->sortBy('size')->values()->all();
            $previous = $batchId ? DB::table('sole_inventory_batches')->where('id', $batchId)->first() : null;
            if ($batchId) {
                abort_unless($previous && $plan && (int) $previous->plan_id === (int) $plan->id, 404);
                abort_if((int) $previous->version !== (int) $data['version'], 409, 'Партію вже змінили. Оновіть сторінку.');
            } else {
                $existing = DB::table('sole_inventory_batches')->where('request_key', $data['request_key'])->first();
                if ($existing) {
                    // MySQL може змінити порядок ключів JSON-об’єкта, тому порівнюємо канонічний вміст.
                    $existingQuantities = collect(json_decode($existing->quantities, true))
                        ->map(fn ($row) => ['size' => $row['size'], 'quantity' => (int) $row['quantity']])->sortBy('size')->values()->all();
                    $same = $plan && (int) $existing->plan_id === (int) $plan->id && $existing->received_on === $data['received_on']
                        && $existingQuantities === $quantities && (string) $existing->note === (string) ($data['note'] ?? null);
                    abort_unless($same, 409, 'Цей запит уже збережений з іншими даними. Оновіть сторінку.');

                    return $existing->id;
                }
            }
            if ($plan && $plan->basis === 'legacy' && $data['received_on'] < $plan->opening_date) {
                throw ValidationException::withMessages(['received_on' => 'Є залишок зі старої версії обліку. Не можна додати партію до його дати, щоб не порахувати її двічі.']);
            }
            if (! $plan) {
                $planId = DB::table('sole_inventory_plans')->insertGetId([
                    'category_id' => $categoryId, 'basis' => 'receipts', 'opening_date' => $data['received_on'], 'opening_balances' => '[]',
                    'lead_time_days' => null, 'safety_days' => 0, 'lookback_days' => 0, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                $planId = $plan->id;
            }
            $values = ['received_on' => $data['received_on'], 'quantities' => json_encode($quantities, JSON_THROW_ON_ERROR),
                'note' => $data['note'] ?? null, 'version' => (int) ($previous->version ?? 0) + 1, 'updated_at' => now()];
            if ($previous) {
                DB::table('sole_inventory_batches')->where('id', $batchId)->update($values);
            } else {
                $batchId = DB::table('sole_inventory_batches')->insertGetId($values + [
                    'plan_id' => $planId, 'request_key' => $data['request_key'], 'user_id' => $userId, 'created_at' => now(),
                ]);
            }
            DB::table('sole_inventory_plan_revisions')->insert([
                'plan_id' => $planId, 'before' => $previous ? json_encode(['batch' => $previous], JSON_THROW_ON_ERROR) : null,
                'after' => json_encode(['batch_id' => $batchId] + $values, JSON_THROW_ON_ERROR), 'user_id' => $userId, 'created_at' => now(),
            ]);

            return $batchId;
        });
    }

    public function addMovement(int $categoryId, array $data, int $userId): int
    {
        return DB::transaction(function () use ($categoryId, $data, $userId) {
            DB::table('categories')->where('id', $categoryId)->lockForUpdate()->first();
            $plan = DB::table('sole_inventory_plans')->where('category_id', $categoryId)->first();
            if (! $plan) {
                throw ValidationException::withMessages(['size' => 'Спочатку додайте отриману партію цієї категорії.']);
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

            if ($plan->basis === 'receipts') {
                if ($data['kind'] === 'receipt') {
                    throw ValidationException::withMessages(['kind' => 'Для надходження скористайтеся дією «Додати партію».']);
                }
                $from = DB::table('sole_inventory_batches')->where('plan_id', $plan->id)->min('received_on');
                if (! $from) {
                    throw ValidationException::withMessages(['size' => 'Спочатку додайте отриману партію.']);
                }
            } else {
                $balance = collect(json_decode($plan->opening_balances, true))->firstWhere('size', $data['size']);
                if (($balance['quantity'] ?? null) === null) {
                    throw ValidationException::withMessages(['size' => 'Для цього розміру немає бази попереднього обліку.']);
                }
                $from = $plan->opening_date;
            }
            if ($data['movement_date'] < $from) {
                throw ValidationException::withMessages(['movement_date' => 'Дата руху не може бути ранішою за початок обліку.']);
            }

            return DB::table('sole_inventory_movements')->insertGetId($data + ['plan_id' => $plan->id, 'user_id' => $userId, 'created_at' => now()]);
        });
    }
}
