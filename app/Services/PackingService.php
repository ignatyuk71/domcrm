<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

class PackingService
{
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
        $order->update([
            'packer_id' => null,
            'packing_status' => 'pending',
            'status_id' => $queueStatusId,
        ]);
    }

    private function queueStatusId(): int
    {
        $queueStatusIds = config('packing.status_ids.queue', []);
        return is_array($queueStatusIds) ? ($queueStatusIds[0] ?? 4) : $queueStatusIds;
    }
}
