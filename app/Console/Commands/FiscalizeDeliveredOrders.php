<?php

namespace App\Console\Commands;

use App\Jobs\FiscalizeOrderJob;
use App\Models\CheckboxSetting;
use App\Models\FiscalReceipt;
use App\Models\Order;
use App\Models\Status;
use App\Services\FiscalQueueService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FiscalizeDeliveredOrders extends Command
{
    // Оновив опис, щоб він відповідав реальності
    protected $signature = 'fiscal:delivered';
    protected $description = 'Автоматична фіскалізація залишків ТІЛЬКИ для забраних замовлень (статус Успішно)';

    public function handle(): int
    {
        $cronLog = Log::channel('cron_fiscal');
        $settings = CheckboxSetting::current();
        $now = Carbon::now(config('app.timezone', 'Europe/Kyiv'));
        $queueEnabled = $settings?->queue_enabled ?? false;
        $withinWindow = $settings ? $settings->isWithinWindow($now) : true;
        if ($settings && !$settings->enabled) {
            $this->info('Fiscal integration is disabled.');
            return self::SUCCESS;
        }
        $statusIds = config('fiscal.status_ids', []);

        // Нам потрібен ТІЛЬКИ фінальний статус, який означає "Забрав/Успішно"
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

        $this->info("Пошук замовлень для фіскалізації (Status ID: {$fiscalizedId})...");

        Order::with(['items', 'fiscalReceipts'])
            // ГОЛОВНА ЗМІНА: Шукаємо тільки статус 11. 
            // Статус 6 (Прибуло) ігноруємо, щоб не бити чек завчасно.
            ->where('status_id', $fiscalizedId) 
            ->where(function ($q) {
                // Беремо тільки ті, де ще немає повної оплати
                $q->where('payment_status', '!=', 'paid')
                    ->orWhereNull('payment_status');
            })
            // Обробляємо шматками по 50, щоб не грузити пам'ять
            ->chunk(50, function ($orders) use ($fiscalizedId, $queueEnabled, $withinWindow, $cronLog) {
                $queueService = app(FiscalQueueService::class);
                
                foreach ($orders as $order) {
                    $totalOrderCents = (int) round($order->items->sum('total') * 100);
                    
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
                        continue; // Йдемо до наступного, чек бити не треба
                    }

                    // 2. Рахуємо залишок, на який треба пробити чек
                    $remaining = $totalOrderCents - $alreadyPaid;
                    
                    // Якщо борг 0 або менше — пропускаємо
                    if ($remaining <= 0) {
                        continue;
                    }

                    if ($queueEnabled && !$withinWindow) {
                        $queueService->enqueue($order, $remaining, FiscalReceipt::TYPE_SELL);
                        $cronLog->info("Fiscal queued Order #{$order->id}: " . ($remaining / 100) . " грн");
                        continue;
                    }

                    $this->info("Клієнт забрав! Фіскалізація залишку для #{$order->id}: " . ($remaining / 100) . " грн");

                    try {
                        // 3. Б'ємо чек синхронно
                        FiscalizeOrderJob::dispatchSync($order, FiscalReceipt::TYPE_SELL, $remaining);
                        
                    } catch (\Throwable $e) {
                        $cronLog->error("CRON Fiscal Error Order #{$order->id}: " . $e->getMessage());
                        $this->error("Помилка для #{$order->id}: " . $e->getMessage());
                    }
                }
            });

        $this->info('Обробка завершена.');
        return self::SUCCESS;
    }
}
