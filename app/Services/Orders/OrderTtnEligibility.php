<?php

namespace App\Services\Orders;

use App\Models\Order;
use Illuminate\Validation\ValidationException;

class OrderTtnEligibility
{
    // Порядок у довіднику не визначає право створювати накладну.
    private const ALLOWED_STATUSES = [
        'confirmed',
        'reserved',
        'packing',
        'packed',
        'shipped',
        'delivered',
        'delivered_paid',
    ];

    private const BLOCKED_STATUS_NAMES = [
        'new' => 'Новий',
        'in_process' => 'В обробці',
        'returned' => 'Повернення',
        'cancelled' => 'Скасовано',
    ];

    public function ensureCanCreate(Order $order): void
    {
        // Довідник має пріоритет; старе поле потрібне лише замовленням без status_id.
        $status = $order->status_id !== null ? $order->statusRef : null;
        $code = $order->status_id !== null
            ? ($status?->type === 'order' ? $status->code : null)
            : $order->status;

        if (in_array($code, self::ALLOWED_STATUSES, true)) {
            return;
        }

        $name = $status?->name ?: (self::BLOCKED_STATUS_NAMES[$code] ?? ($code ?: 'Не визначено'));

        throw ValidationException::withMessages([
            'status' => "Неможливо створити ТТН у статусі «{$name}». Спочатку змініть статус замовлення на «Підтверджено».",
        ]);
    }
}
