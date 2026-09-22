<?php

namespace App\Console\Commands;

use App\Models\ExternalOrderRaw;
use App\Models\Order;
use App\Models\OrderSource;
use App\Services\Integration\AdapterFactory;
use App\Services\Integration\ExternalPaymentSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncExternalPayment extends Command
{
    protected $signature = 'integrations:sync-payment {order : ID замовлення CRM} {--apply : Застосувати підтверджену оплату}';

    protected $description = 'Перевіряє або відновлює оплату одного замовлення зі збереженого запиту сайту';

    public function handle(ExternalPaymentSynchronizer $payments): int
    {
        $order = Order::find($this->argument('order'));
        if (! $order || ! $order->source_id || ! $order->external_id) {
            $this->error('Імпортоване замовлення не знайдено.');

            return self::FAILURE;
        }

        try {
            return DB::transaction(function () use ($order, $payments) {
                $source = OrderSource::query()->whereKey($order->source_id)->lockForUpdate()->firstOrFail();
                $raw = ExternalOrderRaw::query()->where('source_id', $source->id)
                    ->where('external_order_id', $order->external_id)->lockForUpdate()->first();
                $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                $payment = AdapterFactory::for($source)->normalize($raw?->payload ?? [])['payment'] ?? [];
                if (! in_array($payment['status'] ?? null, ['paid', 'prepayment'], true)
                    || ! isset($payment['paid_amount'])) {
                    $this->error('У збереженому запиті немає підтвердження оплати із сумою. Потрібне повторне надсилання із сайту.');

                    return self::FAILURE;
                }

                // Навіть у режимі перегляду перевіряємо всі обмеження й відкочуємо зміни.
                DB::beginTransaction();
                try {
                    $payments->sync($lockedOrder, $payment);
                    $this->line("Замовлення #{$order->id}: {$lockedOrder->payment_status}, сплачено {$lockedOrder->payment()->first()->paid_amount} {$order->currency}.");
                    if ($this->option('apply')) {
                        DB::commit();
                        $this->info('Оплату синхронізовано.');
                    } else {
                        DB::rollBack();
                        $this->info('Перевірка без збереження. Для застосування додайте --apply.');
                    }
                } catch (Throwable $e) {
                    DB::rollBack();
                    throw $e;
                }

                return self::SUCCESS;
            });
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
