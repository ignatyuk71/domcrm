<?php

namespace App\Services;

use App\Models\FiscalReceipt;
use App\Models\Order;
use App\Models\Status;
use Illuminate\Database\Eloquent\Builder;

class FiscalizationEligibility
{
    public const BLOCKED_STATUSES = ['returned', 'cancelled', 'canceled', 'refund'];

    public function automaticStatusId(): int
    {
        return (int) (Status::query()->where('type', 'order')->where('code', 'delivered_paid')->value('id')
            ?? config('fiscal.status_ids.fiscalized', 11));
    }

    public function hasAutomaticFiscalizationStatus(Order $order): bool
    {
        return (int) $order->status_id === $this->automaticStatusId();
    }

    /** Оплата WayForPay та стан доставки — незалежні події. */
    public function isWayForPay(Order $order): bool
    {
        $order->loadMissing('payment');
        $payment = $order->payment;

        return $payment && (
            strtolower(trim((string) $payment->provider)) === 'wayforpay'
            || strtolower(trim((string) $payment->method)) === 'wayforpay'
        );
    }

    public function hasConfirmedOnlinePayment(Order $order, int $totalCents): bool
    {
        if ($order->payment_status !== 'paid' || $totalCents <= 0 || ! $this->isWayForPay($order)) {
            return false;
        }

        $payment = $order->payment;

        // Сам спосіб оплати або позначка paid без підтвердженої суми недостатні.
        return in_array(strtolower((string) $payment->method), ['card', 'wayforpay'], true)
            && strtoupper((string) $order->currency) === 'UAH'
            && strtoupper((string) $payment->currency) === 'UAH'
            && (int) round((float) $payment->paid_amount * 100) >= $totalCents;
    }

    public function isBlocked(Order $order): bool
    {
        $order->loadMissing('statusRef');

        return $order->payment_status === 'refund'
            || in_array($order->status, self::BLOCKED_STATUSES, true)
            || in_array($order->statusRef?->code, self::BLOCKED_STATUSES, true)
            || $order->fiscalReceipts()->where('type', FiscalReceipt::TYPE_RETURN)
                ->where('status', FiscalReceipt::STATUS_SUCCESS)->exists();
    }

    /** SQL звужує вибірку; підтверджену суму повторно перевіряємо перед чеком. */
    public function candidates(Builder $query, int $deliveredStatusId): Builder
    {
        return $query->where('status_id', $deliveredStatusId)->where(function (Builder $q) {
            $q->whereNull('payment_status')->orWhere('payment_status', '!=', 'refund');
        })->whereNotIn('status', self::BLOCKED_STATUSES)
            ->whereDoesntHave('statusRef', fn (Builder $q) => $q->whereIn('code', self::BLOCKED_STATUSES))
            ->whereDoesntHave('fiscalReceipts', fn (Builder $q) => $q
                ->where('type', FiscalReceipt::TYPE_RETURN)->where('status', FiscalReceipt::STATUS_SUCCESS))
            // Не завантажуємо всю історію завершених замовлень кожні п'ять хвилин.
            ->whereRaw('ROUND(COALESCE((SELECT SUM(total) FROM order_items WHERE order_items.order_id = orders.id), 0) * 100)
                > COALESCE((SELECT SUM(total_amount) FROM fiscal_receipts WHERE fiscal_receipts.order_id = orders.id
                    AND fiscal_receipts.type = ? AND fiscal_receipts.status = ?), 0)',
                [FiscalReceipt::TYPE_SELL, FiscalReceipt::STATUS_SUCCESS]);
    }
}
