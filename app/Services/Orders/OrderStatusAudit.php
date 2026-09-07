<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderStatusChange;
use Illuminate\Support\Str;
use RuntimeException;

class OrderStatusAudit
{
    public const SOURCE_LABELS = [
        'manual_status' => 'Ручна зміна',
        'manual_edit' => 'Редагування замовлення',
        'packing' => 'Пакування',
        'packing_auto_release' => 'Автоматичне розблокування пакування',
        'nova_poshta_sync' => 'Автоматична перевірка НП',
        'nova_poshta_manual' => 'Ручна перевірка НП',
        'fiscal' => 'Фіскалізація',
        'system' => 'Системне оновлення',
    ];

    public function record(Order $order, Order $before, Order $after, ?array $context = null): void
    {
        $oldId = $before->status_id === null ? null : (int) $before->status_id;
        $newId = $after->status_id === null ? null : (int) $after->status_id;
        if ($before->status === $after->status && $oldId === $newId) {
            return;
        }

        $route = request()->route()?->getName();
        $context ??= $this->requestContext($route);
        $source = $context['source'];
        // Автоматичний процес не успадковує користувача із синхронної HTTP-джоби.
        $actor = in_array($source, ['nova_poshta_sync', 'packing_auto_release', 'fiscal', 'system'], true)
            ? null : request()->user();
        $names = $order->getConnection()->table('statuses')->where('type', 'order')
            ->whereIn('id', array_values(array_filter([$oldId, $newId])))->pluck('name', 'id');
        $metadata = $this->sanitizeMetadata($context['metadata'] ?? []);
        if ($route) {
            $metadata['route'] = Str::limit($route, 160, '');
        }

        $change = new OrderStatusChange;
        $change->setConnection($order->getConnectionName());
        $saved = $change->fill([
            'order_id' => $order->id,
            'order_number' => $after->order_number,
            'old_status' => $before->status,
            'old_status_id' => $oldId,
            'old_status_name' => $names[$oldId] ?? $before->status,
            'new_status' => $after->status,
            'new_status_id' => $newId,
            'new_status_name' => $names[$newId] ?? $after->status,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'source' => Str::limit($source, 64, ''),
            'reason' => Str::limit($context['reason'], 500, ''),
            'metadata' => $metadata ?: null,
            'occurred_at' => now(),
        ])->saveOrFail();
        if (! $saved) {
            throw new RuntimeException('Не вдалося записати зміну статусу в журнал.');
        }
    }

    private function requestContext(?string $route): array
    {
        [$source, $reason] = match ($route) {
            'orders.updateStatus' => ['manual_status', 'Ручна зміна статусу у списку замовлень'],
            'orders.update' => ['manual_edit', 'Зміна статусу під час збереження картки замовлення'],
            'packing.finish' => ['packing', 'Пакування завершено'],
            'packing.pause' => ['packing', 'Пакування поставлено на паузу'],
            'packing.problem' => ['packing', 'Замовлення позначено як проблемне під час пакування'],
            'packing.release' => ['packing', 'Замовлення повернуто у чергу пакування'],
            default => ['system', 'Зміна статусу через модель замовлення'],
        };

        return compact('source', 'reason');
    }

    /** Копіюємо лише поля статусу: ключі, контактні реквізити й решта payload відкидаються. */
    private function sanitizeMetadata(array $metadata): array
    {
        $safe = [];
        if (isset($metadata['ttn']) && is_scalar($metadata['ttn'])) {
            $safe['ttn'] = Str::limit((string) $metadata['ttn'], 32, '');
        }
        foreach (['StatusCode', 'Status', 'StatusDescription'] as $field) {
            $value = $metadata['np_response'][$field] ?? null;
            if ($value !== null && is_scalar($value)) {
                $safe['np_response'][$field] = Str::limit((string) $value, 1000, '');
            }
        }

        return $safe;
    }
}
