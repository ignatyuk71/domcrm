<?php

namespace App\Services;

use App\Jobs\FiscalizeOrderJob;
use App\Models\CheckboxSetting;
use App\Models\FiscalQueue;
use App\Models\FiscalReceipt;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FiscalQueueService
{
    public function enqueue(Order $order, int $amountCents, string $type, ?Carbon $availableAt = null): ?FiscalQueue
    {
        if ($amountCents <= 0) {
            return null;
        }

        return DB::transaction(function () use ($order, $amountCents, $type, $availableAt) {
            // Повторне підтвердження та крон не створюють паралельних елементів черги.
            Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $exists = FiscalQueue::query()
                ->where('order_id', $order->id)
                ->where('type', $type)
                ->whereIn('status', [FiscalQueue::STATUS_WAITING, FiscalQueue::STATUS_PROCESSING, FiscalQueue::STATUS_ERROR])
                ->first();

            if ($exists) {
                // Вичерпані спроби не обходимо створенням нового елемента без помилки.
                return $exists;
            }

            $settings = CheckboxSetting::current();

            return FiscalQueue::create([
                'order_id' => $order->id,
                'type' => $type,
                'amount_cents' => $amountCents,
                'available_at' => $availableAt ?? ($settings?->nextQueueAvailableAt(now()) ?? now()),
                'status' => FiscalQueue::STATUS_WAITING,
            ]);
        });
    }

    public function enqueueConfirmedOnlinePayment(Order $order): ?FiscalQueue
    {
        $settings = CheckboxSetting::current();
        if (! $settings?->enabled || ! $settings->queue_enabled) {
            return null;
        }

        $eligibility = app(FiscalizationEligibility::class);
        $totalCents = (int) round((float) $order->items()->sum('total') * 100);
        if (! $eligibility->hasConfirmedOnlinePayment($order, $totalCents) || $eligibility->isBlocked($order)) {
            return null;
        }

        $fiscalizedCents = (int) $order->fiscalReceipts()->where('type', FiscalReceipt::TYPE_SELL)
            ->where('status', FiscalReceipt::STATUS_SUCCESS)->sum('total_amount');

        return $this->enqueue($order, $totalCents - $fiscalizedCents, FiscalReceipt::TYPE_SELL);
    }

    /** Скільки разів повторюємо помилковий елемент, перш ніж лишити його в ERROR. */
    public const MAX_ATTEMPTS = 3;

    public function processAvailable(int $limit = 25): int
    {
        $processed = 0;

        // Відновлюємо також обробку, перервану падінням процесу; UUID чека перевіряє джоба.
        $items = FiscalQueue::query()
            ->where('available_at', '<=', now())
            ->where(function ($q) {
                $q->where('status', FiscalQueue::STATUS_WAITING)
                    ->orWhere(fn ($e) => $e->where('status', FiscalQueue::STATUS_ERROR)
                        ->where('attempts', '<', self::MAX_ATTEMPTS))
                    ->orWhere(fn ($stale) => $stale->where('status', FiscalQueue::STATUS_PROCESSING)
                        ->where('updated_at', '<=', now()->subMinutes(10))
                        ->where('attempts', '<', self::MAX_ATTEMPTS));
            })
            ->orderBy('available_at')
            ->limit($limit)
            ->get();

        foreach ($items as $item) {
            // Забираємо елемент атомарно: інший обробник міг уже взяти його після SELECT.
            $claimed = FiscalQueue::query()->whereKey($item->id)->where('status', $item->status)
                ->where('updated_at', $item->getRawOriginal('updated_at'))
                ->where('attempts', $item->attempts)->update(['status' => FiscalQueue::STATUS_PROCESSING]);
            if (! $claimed) {
                continue;
            }
            $item->status = FiscalQueue::STATUS_PROCESSING;

            try {
                $order = Order::query()->with(['items', 'fiscalReceipts'])->find($item->order_id);
                if (! $order) {
                    $item->update([
                        'status' => FiscalQueue::STATUS_ERROR,
                        'last_error' => 'Замовлення не знайдено',
                        'attempts' => self::MAX_ATTEMPTS,
                        'processed_at' => now(),
                    ]);

                    continue;
                }

                $totalOrderCents = (int) round($order->items->sum('total') * 100);
                $eligibility = app(FiscalizationEligibility::class);
                if ($item->type === FiscalReceipt::TYPE_SELL && $eligibility->isBlocked($order)) {
                    $item->update(['status' => FiscalQueue::STATUS_SKIPPED, 'processed_at' => now()]);

                    continue;
                }
                if ($item->type === FiscalReceipt::TYPE_SELL && $eligibility->isWayForPay($order)
                    && ! $eligibility->hasConfirmedOnlinePayment($order, $totalOrderCents)) {
                    $item->update([
                        'status' => FiscalQueue::STATUS_ERROR,
                        'attempts' => self::MAX_ATTEMPTS,
                        'last_error' => 'Онлайн-оплата не підтверджує поточну суму та валюту замовлення',
                        'processed_at' => now(),
                    ]);

                    continue;
                }
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

                // Після ручного чека передоплати залишок міг зменшитися вже після enqueue.
                $amountCents = min((int) $item->amount_cents, $remaining);
                FiscalizeOrderJob::dispatchSync($order, $item->type, $amountCents, automatic: true);

                // Каса Checkbox = один запит одночасно. Пауза між чеками черги
                // страхує від HTTP 429 при обробці пачки.
                usleep(700000);

                // SUCCESS лише якщо чек РЕАЛЬНО пробито (перевіряємо по БД). Джоба могла
                // тихо вийти без винятку (зайнятий лок, порожні goods, shouldFiscalize=false,
                // або Checkbox повернув error-статус) — тоді успішного чека немає.
                $paidAfter = (int) $order->fiscalReceipts()
                    ->where('status', FiscalReceipt::STATUS_SUCCESS)
                    ->where('type', FiscalReceipt::TYPE_SELL)
                    ->sum('total_amount');

                if ($paidAfter > $alreadyPaid) {
                    $item->update(['status' => FiscalQueue::STATUS_SUCCESS, 'last_error' => null, 'processed_at' => now()]);
                    $processed++;
                } else {
                    // Чека немає → не брешемо «success», лишаємо на авторетрай.
                    $item->update([
                        'status' => FiscalQueue::STATUS_ERROR,
                        'attempts' => $item->attempts + 1,
                        'last_error' => 'Чек не пробито: джоба завершилась без успішного чека продажу',
                        'processed_at' => now(),
                    ]);
                    Log::channel('cron_fiscal')->warning("Fiscal Queue #{$item->id}: немає успішного чека після джоби (Order #{$order->id})");
                }
            } catch (\Throwable $e) {
                $attempts = $item->attempts + 1;
                $item->update([
                    'status' => FiscalQueue::STATUS_ERROR,
                    'attempts' => $attempts,
                    'last_error' => $e->getMessage(),
                    'processed_at' => now(),
                ]);

                Log::channel('cron_fiscal')->error("Fiscal Queue Error #{$item->id}: ".$e->getMessage());
            }
        }

        return $processed;
    }
}
