<?php

namespace App\Console\Commands;

use App\Jobs\FiscalizeOrderJob;
use App\Models\CheckboxSetting;
use App\Models\FiscalReceipt;
use App\Models\Order;
use App\Services\FiscalizationEligibility;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FiscalizeShippedPaidOrders extends Command
{
    protected $signature = 'fiscal:shipped-paid';

    protected $description = 'Фіскалізація оплачених відправлених замовлень на рахунок або WayForPay';

    public function handle(FiscalizationEligibility $eligibility): int
    {
        $settings = CheckboxSetting::current();
        if (! $settings?->enabled) {
            return self::SUCCESS;
        }

        $now = Carbon::now(config('app.timezone', 'Europe/Kyiv'));
        if (! $settings->isWithinWindow($now)) {
            return self::SUCCESS;
        }

        $failed = false;
        $eligibility->shippedPaidCandidates(Order::with(['items', 'payment', 'statusRef']))
            ->chunkById(50, function ($orders) use ($eligibility, &$failed) {
                foreach ($orders as $order) {
                    $totalCents = (int) round($order->items->sum('total') * 100);
                    if (! $eligibility->hasConfirmedShippedPayment($order, $totalCents)) {
                        continue;
                    }

                    $fiscalizedCents = (int) $order->fiscalReceipts()->where('type', FiscalReceipt::TYPE_SELL)
                        ->where('status', FiscalReceipt::STATUS_SUCCESS)->sum('total_amount');
                    $remaining = $totalCents - $fiscalizedCents;
                    if ($remaining <= 0) {
                        continue;
                    }

                    try {
                        FiscalizeOrderJob::dispatchSync($order, FiscalReceipt::TYPE_SELL, $remaining,
                            automatic: true, shippedPaid: true);

                        // Відповідь API або завершення джоби ще не означають успішного чека.
                        $paidAfter = (int) $order->fiscalReceipts()->where('type', FiscalReceipt::TYPE_SELL)
                            ->where('status', FiscalReceipt::STATUS_SUCCESS)->sum('total_amount');
                        $currentTotalCents = (int) round($order->items()->sum('total') * 100);
                        if ($paidAfter < $currentTotalCents) {
                            $message = $order->fiscalReceipts()->where('type', FiscalReceipt::TYPE_SELL)
                                ->latest('id')->value('error_message');
                            throw new \RuntimeException($message ?: 'Успішні чеки не покривають повну суму замовлення');
                        }
                    } catch (\Throwable $e) {
                        $failed = true;
                        Log::channel('cron_fiscal')->error('Помилка фіскалізації відправленого оплаченого замовлення', [
                            'order_id' => $order->id,
                            'error' => $e->getMessage(),
                        ]);
                        $this->error("Замовлення №{$order->id}: {$e->getMessage()}");
                    } finally {
                        // Дотримуємося наявної паузи між зверненнями до каси.
                        usleep(700000);
                    }
                }
            });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
