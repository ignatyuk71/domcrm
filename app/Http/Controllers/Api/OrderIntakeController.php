<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessExternalOrder;
use App\Models\ExternalOrderRaw;
use App\Models\OrderSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class OrderIntakeController extends Controller
{
    /**
     * Приймає замовлення із зовнішнього сайту: зберігає сирий payload,
     * ставить у чергу обробку, повертає підтвердження. Замовлення не губиться.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var OrderSource $source */
        $source = $request->attributes->get('external_source');

        $payload = $request->all();
        $externalOrderId = isset($payload['external_order_id']) && $payload['external_order_id'] !== ''
            ? (string) $payload['external_order_id']
            : null;

        // Ідемпотентність: якщо вже приймали це замовлення — не дублюємо.
        if ($externalOrderId) {
            $existing = ExternalOrderRaw::query()
                ->where('source_id', $source->id)
                ->where('external_order_id', $externalOrderId)
                ->first();

            if ($existing) {
                return response()->json([
                    'accepted' => true,
                    'duplicate' => true,
                    'raw_id' => $existing->id,
                    'status' => $existing->status,
                    'order_id' => $existing->order_id,
                ], 200);
            }
        }

        $raw = ExternalOrderRaw::create([
            'source_id' => $source->id,
            'external_order_id' => $externalOrderId,
            'adapter' => $source->adapter,
            'payload' => $payload,
            'status' => ExternalOrderRaw::STATUS_RECEIVED,
            'received_at' => now(),
        ]);

        // Обробка в черзі. При sync-черзі виконається одразу; будь-яку помилку
        // обробника ловимо (статус уже збережено в раві), щоб сайт отримав коректну відповідь.
        try {
            ProcessExternalOrder::dispatch($raw->id);
        } catch (Throwable $e) {
            // no-op: статус failed уже у external_orders_raw
        }

        $raw->refresh();

        return response()->json([
            'accepted' => true,
            'raw_id' => $raw->id,
            'status' => $raw->status,
            'order_id' => $raw->order_id,
            'needs_review' => optional($raw->order)->needs_review,
        ], 202);
    }
}
