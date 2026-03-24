<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Status;
use Illuminate\Support\Facades\DB;

class PackingService
{
    private ?array $statusIdsByCode = null;
    private ?array $statusCodesById = null;

    /**
     * Перевіряє, чи може користувач примусово зняти пакувальника.
     */
    public function canRelease(Order $order, int $userId): bool
    {
        return (int) $order->manager_id === $userId;
    }

    /**
     * Знімає пакувальника та повертає замовлення у чергу.
     */
    public function releaseOrder(Order $order, ?int $releasedBy, string $reason): bool
    {
        return DB::transaction(function () use ($order, $releasedBy, $reason) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            if (!$locked || $locked->packing_status !== 'processing') {
                return false;
            }

            $this->releaseLockedOrder($locked, $releasedBy, $reason);

            return true;
        });
    }

    /**
     * Автоматично розблоковує завислі пакування.
     */
    public function releaseStaleOrders(): int
    {
        $minutes = (int) config('packing.auto_release_minutes', 0);
        if ($minutes <= 0) {
            return 0;
        }

        $cutoff = now()->subMinutes($minutes);
        $orders = Order::query()
            ->where('packing_status', 'processing')
            ->whereHas('activePackingSession', function ($q) use ($cutoff) {
                $q->whereNotNull('started_at')->where('started_at', '<', $cutoff);
            })
            ->get();

        $released = 0;
        foreach ($orders as $order) {
            if ($this->releaseOrder($order, null, 'auto_release')) {
                $released++;
            }
        }

        return $released;
    }

    /**
     * Розблоковує конкретне замовлення, якщо воно прострочене.
     */
    public function releaseIfStale(Order $order): bool
    {
        $minutes = (int) config('packing.auto_release_minutes', 0);
        if ($minutes <= 0 || $order->packing_status !== 'processing') {
            return false;
        }

        $session = $order->activePackingSession()->first();
        if (!$session || !$session->started_at) {
            return false;
        }

        if ($session->started_at->gt(now()->subMinutes($minutes))) {
            return false;
        }

        return $this->releaseOrder($order, null, 'auto_release');
    }

    /**
     * Закриває активну сесію пакування.
     */
    public function closeSession(Order $order, ?int $closedBy, string $reason): void
    {
        $session = $order->activePackingSession()->first();
        if (!$session) {
            return;
        }

        $now = now();
        $duration = $session->started_at ? $now->diffInSeconds($session->started_at) : null;

        $session->update([
            'finished_at' => $now,
            'duration_seconds' => $duration,
            'closed_by' => $closedBy,
            'close_reason' => $reason,
        ]);
    }

    private function releaseLockedOrder(Order $order, ?int $releasedBy, string $reason): void
    {
        $this->closeSession($order, $releasedBy, $reason);

        $queueStatusId = $this->queueStatusId();
        $updates = [
            'packer_id' => null,
            'packing_status' => 'pending',
            'status_id' => $queueStatusId,
        ];

        $statusCode = $this->statusCodeById($queueStatusId);
        if ($statusCode) {
            $updates['status'] = $statusCode;
        }

        $order->update($updates);
    }

    /**
     * ID статусів, які формують чергу пакування.
     */
    public function queueStatusIds(): array
    {
        return $this->resolveStatusIds('queue');
    }

    /**
     * Головний ID статусу черги (для повернення замовлення назад у чергу).
     */
    public function queueStatusId(): int
    {
        $queueStatusIds = $this->queueStatusIds();

        return (int) ($queueStatusIds[0] ?? 4);
    }

    /**
     * ID статусу "Запаковано".
     */
    public function packedStatusId(): int
    {
        $ids = $this->resolveStatusIds('packed');

        return (int) ($ids[0] ?? 12);
    }

    /**
     * ID статусу для "Проблема / Нема товару".
     */
    public function problemStatusId(): ?int
    {
        $ids = $this->resolveStatusIds('problem');

        return $ids[0] ?? null;
    }

    /**
     * ID фінальних/відправлених статусів.
     */
    public function shippedStatusIds(): array
    {
        return $this->resolveStatusIds('shipped');
    }

    /**
     * Код статусу за ID.
     */
    public function statusCodeById(?int $statusId): ?string
    {
        if (!$statusId) {
            return null;
        }

        $this->loadStatusMaps();

        return $this->statusCodesById[(int) $statusId] ?? null;
    }

    /**
     * Резолвить статуси спочатку по code, і лише потім бере fallback ID з config.
     */
    private function resolveStatusIds(string $group): array
    {
        $this->loadStatusMaps();

        $resolved = [];
        $codes = config("packing.status_codes.{$group}", []);
        if (!is_array($codes)) {
            $codes = [$codes];
        }

        foreach ($codes as $code) {
            $normalizedCode = trim((string) $code);
            if ($normalizedCode === '') {
                continue;
            }

            $statusId = $this->statusIdsByCode[$normalizedCode] ?? null;
            if ($statusId) {
                $resolved[] = (int) $statusId;
            }
        }

        if (!empty($resolved)) {
            return array_values(array_unique($resolved));
        }

        $fallbackIds = config("packing.status_ids.{$group}", []);
        if (!is_array($fallbackIds)) {
            $fallbackIds = [$fallbackIds];
        }

        foreach ($fallbackIds as $fallbackId) {
            $normalizedId = (int) $fallbackId;
            if ($normalizedId > 0) {
                $resolved[] = $normalizedId;
            }
        }

        return array_values(array_unique($resolved));
    }

    private function loadStatusMaps(): void
    {
        if ($this->statusIdsByCode !== null && $this->statusCodesById !== null) {
            return;
        }

        $this->statusIdsByCode = [];
        $this->statusCodesById = [];

        $rows = Status::query()
            ->where('type', 'order')
            ->get(['id', 'code']);

        foreach ($rows as $row) {
            $statusId = (int) $row->id;
            $statusCode = trim((string) $row->code);
            if ($statusCode === '' || $statusId <= 0) {
                continue;
            }

            $this->statusIdsByCode[$statusCode] = $statusId;
            $this->statusCodesById[$statusId] = $statusCode;
        }
    }
}
