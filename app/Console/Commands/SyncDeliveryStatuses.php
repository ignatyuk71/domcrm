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
    protected $signature = 'delivery:sync-statuses {--chunk=50 : Кількість замовлень у одному запиті до API}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Оновлює статуси доставки (Нова Пошта) для активних відправлень';

    private const DEFAULT_CHUNK = 50;
    private const MAX_CHUNK = 100; // Ліміт НП на кількість документів в одному запиті
    private const TTN_PATTERN = '/^\d{14}$/';

    /**
     * Фінальні статуси замовлення (не треба опитувати доставку).
     * Сюди додано 'delivered_paid' (ID 11), щоб зупинити опитування після отримання.
     */
    protected array $finalOrderStatuses = [
        'completed',
        'done',
        'delivered',      // ID 6 (залежить від логіки, іноді тут ще чекають отримання)
        'returned',       // ID 8
        'cancelled',      // ID 7
        'canceled',
        'delivered_paid', // <--- ID 11 (Успішно завершено/Забрано). Додано для економії запитів.
    ];

    public function handle(NovaPoshtaService $novaPoshta): int
    {
        $chunk = (int) $this->option('chunk');
        $chunk = $chunk > 0 ? $chunk : self::DEFAULT_CHUNK;
        $chunk = max(1, min($chunk, self::MAX_CHUNK));

        // Шукаємо тільки ті замовлення, статус яких НЕ у списку $finalOrderStatuses
        $query = Order::query()
            ->whereNotIn('status', $this->finalOrderStatuses) // Тут фільтрується ID 11 (delivered_paid)
            ->whereHas('delivery', function ($q) {
                $q->where('carrier', 'nova_poshta')
                    ->whereNotNull('ttn');
            })
            ->with(['delivery', 'customer'])
            ->orderBy('id');

        $total = (clone $query)->count();
        $this->info("Знайдено {$total} замовлень для оновлення статусів доставки.");

        $checked = 0;
        $updated = 0;
        $failed = 0;
        $skipped = 0;

        $query->chunkById($chunk, function (Collection $orders) use ($novaPoshta, &$checked, &$updated, &$failed, &$skipped) {
            $mapByTtn = [];
            $documents = [];

            foreach ($orders as $order) {
                $delivery = $order->delivery;
                if (!$delivery || !$delivery->ttn) {
                    continue;
                }
                $ttn = trim((string) $delivery->ttn);
                if (!preg_match(self::TTN_PATTERN, $ttn)) {
                    Log::warning('NovaPoshta tracking skip: invalid TTN', [
                        'order_id' => $order->id,
                        'ttn' => $ttn,
                    ]);
                    $skipped++;
                    continue;
                }

                $phone = $delivery->recipient_phone ?: ($order->customer?->phone ?? null);
                $phone = $phone ? preg_replace('/\D+/', '', (string) $phone) : null;
                $documents[] = [
                    'DocumentNumber' => $ttn,
                    'Phone' => $phone,
                ];
                $mapByTtn[$ttn] = [
                    'delivery' => $delivery,
                    'order' => $order,
                ];
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
                $entry = $mapByTtn[$ttn];
                $delivery = $entry['delivery'];
                
                // Оновлюємо інформацію про доставку (текст, іконку)
                $delivery->forceFill([
                    'delivery_status_code' => $normalized['code'],
                    'delivery_status_label' => $normalized['label'],
                    'delivery_status_description' => $normalized['description'],
                    'delivery_status_color' => $normalized['color'],
                    'delivery_status_icon' => $normalized['icon'],
                    'delivery_status_updated_at' => $now,
                    'last_tracked_at' => $now,
                ])->saveQuietly();
                $delivery->syncStatusHistory($normalized, $now);

                // Отримуємо ID статусу для CRM через Mapper
                $npCode = (int) ($row['StatusCode'] ?? $row['Status'] ?? 0);
                $newStatusId = DeliveryStatusMapper::getCrmStatusId($npCode);

                if ($newStatusId) {
                    $order = $entry['order'];
                    // Якщо статус змінився - оновлюємо
                    if ($order->status_id !== $newStatusId) {
                        $order->update(['status_id' => $newStatusId]);
                        // Тут можна додати Log::info, щоб бачити зміни в консолі/логах
                    }
                }

                $updated++;
            }

            $checked += count($documents);
        }, column: 'id');

        $this->info("Перевірено: {$checked}, оновлено: {$updated}, помилки: {$failed}, пропущено: {$skipped}");

        return self::SUCCESS;
    }
}
