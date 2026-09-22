<?php

namespace App\Jobs;

use App\Models\ExternalOrderRaw;
use App\Models\OrderSource;
use App\Services\Integration\AdapterFactory;
use App\Services\Integration\ExternalOrderImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Обробка одного сирого вхідного замовлення: адаптер → канонічний формат → створення Order.
 */
class ProcessExternalOrder implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(public int $rawId)
    {
    }

    public function handle(ExternalOrderImporter $importer): void
    {
        $raw = ExternalOrderRaw::find($this->rawId);
        if (!$raw || $raw->status === ExternalOrderRaw::STATUS_PROCESSED) {
            return; // нема чого робити / вже оброблено (ідемпотентність)
        }

        try {
            DB::transaction(function () use (&$raw, $importer) {
                // Той самий порядок блокувань, що й у прийманні оновлень.
                OrderSource::query()->whereKey($raw->source_id)->lockForUpdate()->firstOrFail();
                $locked = ExternalOrderRaw::query()->whereKey($raw->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === ExternalOrderRaw::STATUS_PROCESSED) {
                    return;
                }
                $raw = $locked;
                $locked->update(['status' => ExternalOrderRaw::STATUS_PROCESSING]);
                $source = $locked->source;
                $canonical = AdapterFactory::for($source)->normalize($locked->payload ?? []);
                $order = $importer->import($source, $canonical, $locked);
                $locked->update([
                    'status' => ExternalOrderRaw::STATUS_PROCESSED,
                    'order_id' => $order->id,
                    'external_order_id' => $locked->external_order_id ?: ($canonical['external_order_id'] ?? null),
                    'processed_at' => now(),
                    'error' => null,
                ]);
            });
        } catch (Throwable $e) {
            Log::error('ProcessExternalOrder failed', [
                'raw_id' => $raw->id,
                'source_id' => $raw->source_id,
                'message' => $e->getMessage(),
            ]);

            DB::transaction(function () use ($raw, $e) {
                OrderSource::query()->whereKey($raw->source_id)->lockForUpdate()->firstOrFail();
                $current = ExternalOrderRaw::query()->whereKey($raw->id)->lockForUpdate()->first();
                // Не перекриваємо результат новішого повідомлення старою помилкою.
                if ($current && $current->payload === $raw->payload && $current->status !== ExternalOrderRaw::STATUS_PROCESSED) {
                    $current->update([
                        'status' => ExternalOrderRaw::STATUS_FAILED,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

            throw $e; // дозволяємо черзі повторити (retry) у async-режимі
        }
    }
}
