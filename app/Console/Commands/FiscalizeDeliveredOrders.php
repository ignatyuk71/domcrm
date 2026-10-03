<?php

namespace App\Console\Commands;

use App\Jobs\FiscalizeOrderJob;
use App\Models\CheckboxSetting;
use App\Models\FiscalReceipt;
use App\Models\Order;
use App\Models\Status;
use App\Services\FiscalQueueService;
use App\Services\FiscalizationEligibility;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FiscalizeDeliveredOrders extends Command
{
    protected $signature = 'fiscal:delivered';
    protected $description = 'Фіскалізація отриманих замовлень і підтверджених онлайн-оплат WayForPay';

    public function handle(): int
    {
        $cronLog = Log::channel('cron_fiscal');
        $settings = CheckboxSetting::current();
        $now = Carbon::now(config('app.timezone', 'Europe/Kyiv'));
        $queueEnabled = $settings?->queue_enabled ?? false;
        $withinWindow = $settings ? $settings->isWithinWindow($now) : true;
        $queueAt = $settings?->queueProcessAt($now);
        $beforeQueueTime = $queueAt ? $now->lessThan($queueAt) : false;
        if ($settings && !$settings->enabled) {
            $this->info('Fiscal integration is disabled.');
            return self::SUCCESS;
        }
        $statusIds = config('fiscal.status_ids', []);

        // Для післяплати чекаємо отримання; WayForPay має окрему умову підтвердженої оплати.
        $fiscalizedCode = 'delivered_paid';
        $fiscalizedId = Status::query()
            ->where('type', 'order')
            ->where('code', $fiscalizedCode)
            ->value('id');

        if (!$fiscalizedId) {
            $fiscalizedId = (int) ($statusIds['fiscalized'] ?? 11);
            $cronLog->warning('Fiscal status code not found, fallback to status_id', [
                'status_code' => $fiscalizedCode,
                'fallback_id' => $fiscalizedId,
            ]);
        }

        $this->info('Пошук отриманих замовлень і підтверджених онлайн-оплат для фіскалізації...');
        $eligibility = app(FiscalizationEligibility::class);

        $eligibility->candidates(Order::with(['items', 'payment', 'statusRef']), $fiscalizedId)
            // paid не є доказом чека; пагінація за ID не пропускає рядки після оновлення.
            ->chunkById(50, function ($orders) use ($queueEnabled, $withinWindow, $beforeQueueTime, $queueAt, $cronLog, $eligibility) {
                $queueService = app(FiscalQueueService::class);
                
                foreach ($orders as $order) {
                    $totalOrderCents = (int) round($order->items->sum('total') * 100);
                    if ($eligibility->isWayForPay($order)
                        && ! $eligibility->hasConfirmedOnlinePayment($order, $totalOrderCents)) {
                        $cronLog->warning('Fiscal skip: online payment is not confirmed for current total/currency', [
                            'order_id' => $order->id,
                        ]);
                        continue;
                    }
                    
                    // Рахуємо суму вже існуючих успішних чеків продажу
                    $alreadyPaid = (int) $order->fiscalReceipts()
                        ->where('status', FiscalReceipt::STATUS_SUCCESS)
                        ->where('type', FiscalReceipt::TYPE_SELL)
                        ->sum('total_amount');

                    // 1. Перевірка: чи вже все сплачено?
                    if ($alreadyPaid >= $totalOrderCents && $totalOrderCents > 0) {
                        // Якщо чеки вже є на всю суму, просто ставимо 'paid' в базі
                        if ($order->payment_status !== 'paid') {
                            $order->update(['payment_status' => 'paid']);
                            $this->info("Замовлення #{$order->id} вже фіскалізоване. Статус оновлено на paid.");
                        }
                        $cronLog->info('Fiscal skip: already paid', [
                            'order_id' => $order->id,
                            'status_id' => $order->status_id,
                            'status' => $order->status,
                            'payment_status' => $order->payment_status,
                            'total_cents' => $totalOrderCents,
                            'already_paid_cents' => $alreadyPaid,
                        ]);
                        continue; // Йдемо до наступного, чек бити не треба
                    }

                    // 2. Рахуємо залишок, на який треба пробити чек
                    $remaining = $totalOrderCents - $alreadyPaid;
                    
                    // Якщо борг 0 або менше — пропускаємо
                    if ($remaining <= 0) {
                        $cronLog->info('Fiscal skip: non-positive remaining', [
                            'order_id' => $order->id,
                            'status_id' => $order->status_id,
                            'status' => $order->status,
                            'payment_status' => $order->payment_status,
                            'total_cents' => $totalOrderCents,
                            'already_paid_cents' => $alreadyPaid,
                            'remaining_cents' => $remaining,
                        ]);
                        continue;
                    }

                    // Одна черга для онлайн-оплат і післяплати: повтори не обходять ліміт спроб.
                    if ($queueEnabled) {
                        $queued = $queueService->enqueue($order, $remaining, FiscalReceipt::TYPE_SELL);
                        if ($queued?->wasRecentlyCreated) {
                            $cronLog->info('Fiscal queued', [
                                'order_id' => $order->id,
                                'status_id' => $order->status_id,
                                'status' => $order->status,
                                'payment_status' => $order->payment_status,
                                'total_cents' => $totalOrderCents,
                                'already_paid_cents' => $alreadyPaid,
                                'remaining_cents' => $remaining,
                                'queue_at' => $queueAt?->toDateTimeString(),
                                'reason' => ! $withinWindow ? 'outside_window' : ($beforeQueueTime ? 'before_queue_time' : 'ready'),
                            ]);
                        }
                        continue;
                    }

                    // Якщо черга вимкнена або вікно закрите — нічого не робимо до часу фіскалізації
                    if (!$withinWindow || $beforeQueueTime) {
                        $cronLog->info('Fiscal skip: waiting for queue time/window', [
                            'order_id' => $order->id,
                            'status_id' => $order->status_id,
                            'status' => $order->status,
                            'payment_status' => $order->payment_status,
                            'total_cents' => $totalOrderCents,
                            'already_paid_cents' => $alreadyPaid,
                            'remaining_cents' => $remaining,
                            'queue_enabled' => $queueEnabled,
                            'queue_at' => $queueAt?->toDateTimeString(),
                        ]);
                        continue;
                    }

                    $this->info("Фіскалізація залишку для #{$order->id}: " . ($remaining / 100) . ' грн');

                    try {
                        // 3. Б'ємо чек синхронно
                        FiscalizeOrderJob::dispatchSync($order, FiscalReceipt::TYPE_SELL, $remaining, automatic: true);

                        // Каса Checkbox обробляє ТІЛЬКИ один запит одночасно — без паузи
                        // пачка чеків (особливо backlog) ловить HTTP 429 "Занадто часто".
                        // Невелика затримка між чеками робить розгрібання чистим.
                        usleep(700000);
                    } catch (\Throwable $e) {
                        $cronLog->error('CRON Fiscal Error', [
                            'order_id' => $order->id,
                            'status_id' => $order->status_id,
                            'status' => $order->status,
                            'payment_status' => $order->payment_status,
                            'total_cents' => $totalOrderCents,
                            'already_paid_cents' => $alreadyPaid,
                            'remaining_cents' => $remaining,
                            'error' => $e->getMessage(),
                        ]);
                        $this->error("Помилка для #{$order->id}: " . $e->getMessage());
                    }
                }
            });

        $this->info('Обробка завершена.');
        return self::SUCCESS;
    }
}
