<?php

namespace App\Console\Commands;

use App\Models\OrderDelivery;
use App\Services\NovaPoshtaDeliveryCosts;
use App\Services\NovaPoshtaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncDeliveryCosts extends Command
{
    protected $signature = 'delivery:sync-costs {--limit=500 : Максимум накладних за запуск} {--sender-only : Лише відправник за даними CRM або НП}';

    protected $description = 'Отримати DocumentCost і платника з API НП без зміни статусів чи фіскалізації';

    public function handle(NovaPoshtaService $np, NovaPoshtaDeliveryCosts $costs): int
    {
        $limit = max(1, min(10000, (int) $this->option('limit')));
        $query = OrderDelivery::query()->where('carrier', 'nova_poshta')
            ->whereNotNull('ttn')->whereRaw("TRIM(ttn) <> ''")
            ->when($this->option('sender-only'), fn ($q) => $q->where(fn ($q) => $q
                ->where('delivery_payer', 'sender')->orWhere('np_payer_type', 'sender')))
            ->where(fn ($q) => $q->whereNull('np_cost_attempted_at')->orWhere('np_cost_attempted_at', '<', now()->subDay())
                ->orWhereColumn('np_cost_ttn', '!=', 'ttn'))
            ->orderBy('np_cost_attempted_at')->orderBy('id')->limit($limit);
        $updated = 0;
        $failed = 0;
        // Матеріалізуємо обмежений набір до оновлення поля сортування.
        foreach ($query->get()->chunk(100) as $deliveries) {
            $attemptedAt = now();
            foreach ($deliveries as $delivery) {
                DB::table('order_deliveries')->where('id', $delivery->id)->where('ttn', $delivery->ttn)
                    ->update(['np_cost_attempted_at' => $attemptedAt]);
            }
            $response = $np->getStatuses($deliveries->map(fn ($d) => [
                'DocumentNumber' => trim($d->ttn), 'Phone' => $d->recipient_phone,
            ])->unique('DocumentNumber')->values()->all());
            $rows = collect($response['data'] ?? [])->keyBy(fn ($row) => (string) ($row['Number'] ?? $row['IntDocNumber'] ?? $row['DocumentNumber'] ?? ''));
            foreach ($deliveries as $delivery) {
                $row = $rows->get(trim($delivery->ttn));
                if ($row && $costs->store($delivery, $row, now())) {
                    $updated++;
                } else {
                    $failed++;
                }
            }
        }
        $this->info("Оновлено відповідей НП: {$updated}; не отримано: {$failed}. Статуси замовлень не змінено.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
