<?php

namespace App\Console\Commands;

use App\Jobs\FiscalizeOrderJob;
use App\Models\FiscalReceipt;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FiscalizeDeliveredOrders extends Command
{
    // Оновив опис, щоб він відповідав реальності
    protected $signature = 'fiscal:delivered';
    protected $description = 'Автоматична фіскалізація залишків ТІЛЬКИ для забраних замовлень (статус Успішно)';

    public function handle(): int
    {
        $statusIds = config('fiscal.status_ids', []);
        
        // Нам потрібен ТІЛЬКИ фінальний статус (ID 11), який означає "Забрав/Успішно"
        $fiscalizedId = (int) ($statusIds['fiscalized'] ?? 11);

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
            ->chunk(50, function ($orders) use ($fiscalizedId) {
                
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

                    $this->info("Клієнт забрав! Фіскалізація залишку для #{$order->id}: " . ($remaining / 100) . " грн");

                    try {
                        // 3. Б'ємо чек синхронно
                        FiscalizeOrderJob::dispatchSync($order, FiscalReceipt::TYPE_SELL, $remaining);
                        
                    } catch (\Throwable $e) {
                        Log::error("CRON Fiscal Error Order #{$order->id}: " . $e->getMessage());
                        $this->error("Помилка для #{$order->id}: " . $e->getMessage());
                    }
                }
            });

        $this->info('Обробка завершена.');
        return self::SUCCESS;
    }
}