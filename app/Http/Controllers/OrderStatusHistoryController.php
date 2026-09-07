<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Orders\OrderStatusAudit;
use Illuminate\Http\JsonResponse;

class OrderStatusHistoryController extends Controller
{
    public function __invoke(Order $order): JsonResponse
    {
        // Окремий запит при відкритті журналу; список замовлень не отримує зайвого навантаження.
        $history = $order->statusChanges()->orderByDesc('id')->simplePaginate(20);
        $history->through(function ($change) {
            $change->setAttribute('source_label', OrderStatusAudit::SOURCE_LABELS[$change->source] ?? 'Системне оновлення');

            return $change;
        });

        return response()->json($history);
    }
}
