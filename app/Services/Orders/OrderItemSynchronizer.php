<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderItemSynchronizer
{
    /** Викликається в транзакції після блокування замовлення. */
    public function sync(Order $order, array $rows, ?array $deletedItemIds = null): void
    {
        $existing = $order->items()->lockForUpdate()->get()->keyBy('id');
        $deletedIds = array_map('intval', $deletedItemIds ?? []);
        $explicitIds = array_map('intval', array_filter(array_column($rows, 'id'), fn ($id) => $id !== null));

        // Повторна перевірка захищає від видалення позиції між валідацією та блокуванням.
        foreach (array_merge($explicitIds, $deletedIds) as $id) {
            if (! $existing->has($id)) {
                throw ValidationException::withMessages(['items' => 'Позиції замовлення змінилися. Оновіть сторінку та повторіть збереження.']);
            }
        }
        if (array_intersect($explicitIds, $deletedIds)) {
            throw ValidationException::withMessages(['deleted_item_ids' => 'Позицію не можна одночасно змінювати й видаляти.']);
        }

        $productIds = array_merge(array_column($rows, 'product_id'), $existing->pluck('product_id')->all());
        $productCosts = DB::table('products')->whereIn('id', array_unique(array_filter($productIds)))->pluck('cost_price', 'id');
        $variantIds = array_merge(array_column($rows, 'product_variant_id'), $existing->pluck('product_variant_id')->all());
        $variantParents = DB::table('product_variants')->whereIn('id', array_unique(array_filter($variantIds)))->pluck('product_id', 'id');

        $claimedIds = array_fill_keys(array_merge($explicitIds, $deletedIds), true);
        $savedIds = [];
        foreach ($rows as $index => $row) {
            $id = isset($row['id']) ? (int) $row['id'] : null;
            $item = $id ? $existing->get($id) : null;
            if (! $item && $deletedItemIds === null) {
                $item = $this->legacyMatch($existing->except(array_keys($claimedIds)), $row, $index);
            }

            $productId = array_key_exists('product_id', $row) ? $this->id($row['product_id']) : $this->id($item?->product_id);
            $variantId = array_key_exists('product_variant_id', $row) ? $this->id($row['product_variant_id']) : $this->id($item?->product_variant_id);
            if ($variantId !== null && ($productId === null || (int) $variantParents->get($variantId) !== $productId)) {
                throw ValidationException::withMessages(["items.{$index}.product_variant_id" => 'Обраний розмір не належить обраному товару.']);
            }

            $sameProduct = $item && $productId === $this->id($item->product_id)
                && $variantId === $this->id($item->product_variant_id);
            $attributes = [
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                // NULL і нуль також є історичними значеннями, їх не підміняємо каталогом.
                'cost_price' => $sameProduct ? $item->cost_price : ($productId ? $productCosts->get($productId) : null),
                'qty' => $row['qty'],
                'price' => $row['price'],
                'total' => $row['qty'] * $row['price'],
            ];
            foreach (['title' => 'product_title', 'sku' => 'sku', 'size' => 'size', 'color' => 'color', 'external_id' => 'external_id'] as $input => $field) {
                $attributes[$field] = array_key_exists($input, $row) ? $row[$input] : $item?->{$field};
            }

            if ($item) {
                $item->update($attributes);
            } else {
                $item = $order->items()->create($attributes);
            }
            $savedIds[] = $item->id;
            $claimedIds[$item->id] = true;
        }

        if ($deletedItemIds === null && $existing->keys()->diff($savedIds)->isNotEmpty()) {
            throw ValidationException::withMessages(['items' => 'Для безпечної зміни списку позицій оновіть сторінку та повторіть редагування.']);
        }
        // Стара вкладка не видаляє позиції, яких не було в її початковому списку.
        if ($deletedIds !== []) {
            $order->items()->whereKey($deletedIds)->delete();
        }
        $order->unsetRelation('items');
    }

    /** Старі клієнти без ID можуть зберегти лише однозначно визначені позиції. */
    private function legacyMatch(Collection $available, array $row, int $index): ?OrderItem
    {
        $productId = $this->id($row['product_id'] ?? null);
        $variantId = $this->id($row['product_variant_id'] ?? null);
        if ($productId !== null || $variantId !== null) {
            $matches = $available->filter(fn ($item) => $this->id($item->product_id) === $productId
                && $this->id($item->product_variant_id) === $variantId);
        } else {
            $sku = trim((string) ($row['sku'] ?? ''));
            $title = trim((string) ($row['title'] ?? ''));
            if ($sku === '' && $title === '') {
                return null;
            }
            $size = trim((string) ($row['size'] ?? ''));
            $matches = $available->filter(fn ($item) => $item->product_id === null && $item->product_variant_id === null
                && trim((string) $item->size) === $size
                && ($sku !== '' ? trim((string) $item->sku) === $sku : trim((string) $item->product_title) === $title));
        }

        if ($matches->count() > 1) {
            throw ValidationException::withMessages(["items.{$index}.id" => 'Неможливо однозначно визначити позицію. Оновіть сторінку.']);
        }

        return $matches->first();
    }

    private function id(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
