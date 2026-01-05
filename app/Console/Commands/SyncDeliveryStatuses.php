<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\NovaPoshtaService;
use App\Support\DeliveryStatusMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SyncDeliveryStatuses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'delivery:sync-statuses {--chunk=200 : Кількість замовлень у одному запиті до API}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Оновлює статуси доставки (Нова Пошта) для активних відправлень';

    /**
     * Фінальні статуси замовлення (не треба опитувати доставку).
     */
    protected array $finalOrderStatuses = [
        'completed',
        'done',
        'delivered',
        'returned',
        'cancelled',
        'canceled',
    ];

    public function handle(NovaPoshtaService $novaPoshta): int
    {
        $chunk = (int) $this->option('chunk') ?: 200;
        $chunk = max(1, $chunk);

        $query = Order::query()
            ->whereNotIn('status', $this->finalOrderStatuses)
            ->whereHas('delivery', function ($q) {
                $q->where('carrier', 'nova_poshta')
                    ->whereNotNull('ttn');
            })
            ->with('delivery')
            ->orderBy('id');

        $total = (clone $query)->count();
        $this->info("Знайдено {$total} замовлень для оновлення статусів доставки.");

        $checked = 0;
        $updated = 0;
        $failed = 0;

        $query->chunkById($chunk, function (Collection $orders) use ($novaPoshta, &$checked, &$updated, &$failed) {
            $mapByTtn = [];
            $documents = [];

            foreach ($orders as $order) {
                $delivery = $order->delivery;
                if (!$delivery || !$delivery->ttn) {
                    continue;
                }
                $documents[] = ['DocumentNumber' => $delivery->ttn, 'Phone' => $delivery->recipient_phone];
                $mapByTtn[$delivery->ttn] = $delivery;
            }

            if (empty($documents)) {
                return;
            }

            $response = $novaPoshta->getStatuses($documents);

            if (!($response['success'] ?? false)) {
                $failed += count($documents);
                Log::warning('NovaPoshta tracking error', [
                    'errors' => $response['errors'] ?? ['unknown'],
                    'info' => $response['info'] ?? null,
                ]);
                return;
            }

            $now = now();
            foreach ($response['data'] ?? [] as $row) {
                $ttn = $row['Number'] ?? $row['IntDocNumber'] ?? $row['DocumentNumber'] ?? null;
                if (!$ttn || !isset($mapByTtn[$ttn])) {
                    continue;
                }

                $normalized = DeliveryStatusMapper::map($row);
                $delivery = $mapByTtn[$ttn];
                $delivery->forceFill([
                    'delivery_status_code' => $normalized['code'],
                    'delivery_status_label' => $normalized['label'],
                    'delivery_status_description' => $normalized['description'],
                    'delivery_status_color' => $normalized['color'],
                    'delivery_status_icon' => $normalized['icon'],
                    'delivery_status_updated_at' => $now,
                    'last_tracked_at' => $now,
                ])->saveQuietly();

                $updated++;
            }

            $checked += count($documents);
        }, column: 'id');

        $this->info("Перевірено: {$checked}, оновлено: {$updated}, помилки: {$failed}");

        return self::SUCCESS;
    }
}
