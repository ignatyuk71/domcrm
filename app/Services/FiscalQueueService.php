<?php

namespace App\Services;

use App\Jobs\FiscalizeOrderJob;
use App\Models\CheckboxSetting;
use App\Models\FiscalQueue;
use App\Models\FiscalReceipt;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class FiscalQueueService
{
    public function enqueue(Order $order, int $amountCents, string $type, ?Carbon $availableAt = null): ?FiscalQueue
    {
        $exists = FiscalQueue::query()
            ->where('order_id', $order->id)
            ->whereIn('status', [FiscalQueue::STATUS_WAITING, FiscalQueue::STATUS_PROCESSING])
            ->first();

        if ($exists) {
            return $exists;
        }

        $settings = CheckboxSetting::current();
        $availableAt = $availableAt ?? ($settings?->nextQueueAvailableAt(now()) ?? now());

        return FiscalQueue::create([
            'order_id' => $order->id,
            'type' => $type,
            'amount_cents' => $amountCents,
            'available_at' => $availableAt,
            'status' => FiscalQueue::STATUS_WAITING,
        ]);
    }

    public function processAvailable(int $limit = 25): int
    {
        $processed = 0;

        $items = FiscalQueue::query()
            ->where('status', FiscalQueue::STATUS_WAITING)
            ->where('available_at', '<=', now())
            ->orderBy('available_at')
            ->limit($limit)
            ->get();

        foreach ($items as $item) {
            $item->update(['status' => FiscalQueue::STATUS_PROCESSING]);

            try {
                $order = Order::query()->with(['items', 'fiscalReceipts'])->find($item->order_id);
                if (!$order) {
                    $item->update([
                        'status' => FiscalQueue::STATUS_ERROR,
                        'last_error' => 'Order not found',
                        'processed_at' => now(),
                    ]);
                    continue;
                }

                $totalOrderCents = (int) round($order->items->sum('total') * 100);
                $alreadyPaid = (int) $order->fiscalReceipts()
                    ->where('status', FiscalReceipt::STATUS_SUCCESS)
                    ->where('type', FiscalReceipt::TYPE_SELL)
                    ->sum('total_amount');

                $remaining = $totalOrderCents - $alreadyPaid;
                if ($remaining <= 0) {
                    $item->update([
                        'status' => FiscalQueue::STATUS_SKIPPED,
                        'processed_at' => now(),
                    ]);
                    continue;
                }

                FiscalizeOrderJob::dispatchSync($order, $item->type, $item->amount_cents);

                $item->update([
                    'status' => FiscalQueue::STATUS_SUCCESS,
                    'processed_at' => now(),
                ]);
                $processed++;
            } catch (\Throwable $e) {
                $attempts = $item->attempts + 1;
                $item->update([
                    'status' => FiscalQueue::STATUS_ERROR,
                    'attempts' => $attempts,
                    'last_error' => $e->getMessage(),
                    'processed_at' => now(),
                ]);

                Log::channel('cron_fiscal')->error("Fiscal Queue Error #{$item->id}: " . $e->getMessage());
            }
        }

        return $processed;
    }
}
