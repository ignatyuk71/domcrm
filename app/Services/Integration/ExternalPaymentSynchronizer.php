<?php

namespace App\Services\Integration;

use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ExternalPaymentSynchronizer
{
    /** Викликається у транзакції імпорту; фіскальні дані та передоплату не змінює. */
    public function sync(Order $order, array $data): void
    {
        $status = $data['status'] ?? null;
        if (! in_array($status, ['unpaid', 'prepayment', 'paid'], true)) {
            return;
        }

        $payment = $order->payment()->firstOrFail();

        // Повторне чи запізніле повідомлення не скасовує вже підтверджену оплату.
        if ($order->payment_status === 'refund'
            || ($order->payment_status === 'paid' && $status !== 'paid')
            || ($order->payment_status === 'prepayment' && $status === 'unpaid')) {
            return;
        }

        if (strtoupper((string) ($data['currency'] ?? $order->currency)) !== strtoupper($order->currency)) {
            throw ValidationException::withMessages(['payment.currency' => 'Валюта платежу не збігається з валютою замовлення.']);
        }

        $total = round((float) $order->items()->sum('total'), 2);
        $amount = $data['paid_amount'] ?? null;
        if (in_array($status, ['paid', 'prepayment'], true) && $amount === null) {
            throw ValidationException::withMessages(['payment.paid_amount' => 'Для підтвердження оплати потрібна сплачена сума.']);
        }
        if ($amount !== null && (! is_numeric($amount) || ! is_finite((float) $amount) || (float) $amount < 0 || (float) $amount > 9999999999.99)) {
            throw ValidationException::withMessages(['payment.paid_amount' => 'Некоректна сплачена сума.']);
        }
        $amount = round((float) ($amount ?? 0), 2);
        if (($status === 'paid' && ($total <= 0 || $amount < $total))
            || ($status === 'prepayment' && ($amount <= 0 || $amount >= $total))
            || ($status === 'unpaid' && $amount > 0)) {
            throw ValidationException::withMessages(['payment.paid_amount' => 'Сплачена сума не відповідає статусу оплати.']);
        }
        if ($payment->paid_amount !== null && $amount < (float) $payment->paid_amount) {
            return;
        }

        $updates = ['paid_amount' => $amount];
        foreach (['provider', 'transaction_id', 'paid_at'] as $field) {
            if (! empty($data[$field])) {
                $updates[$field] = $field === 'paid_at'
                    ? Carbon::parse($data[$field])->setTimezone(config('app.timezone'))
                    : $data[$field];
            }
        }
        if (! empty($data['method'])) {
            $updates['method'] = $data['method'];
        }
        $payment->update($updates);
        $order->update(['payment_status' => $status]);
    }
}
