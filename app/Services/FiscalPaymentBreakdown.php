<?php

namespace App\Services;

use App\Models\FiscalReceipt;

class FiscalPaymentBreakdown
{
    public function methods(FiscalReceipt $receipt): array
    {
        if (! in_array($receipt->type, [FiscalReceipt::TYPE_SELL, FiscalReceipt::TYPE_RETURN], true)) {
            return [];
        }

        $payment = $receipt->order?->payment;
        $fallback = $this->orderMethod($payment?->method, $payment?->provider);
        $payments = $receipt->meta['payments'] ?? [];
        if (! is_array($payments) || $payments === []) {
            return [$fallback];
        }

        $methods = [];
        foreach ($payments as $item) {
            if (! is_array($item) || ! is_numeric($item['value'] ?? null) || $item['value'] < 0) {
                $methods[] = 'unknown';
                continue;
            }
            if ((float) $item['value'] === 0.0) {
                continue;
            }

            $type = strtoupper((string) ($item['type'] ?? ''));
            $label = (string) ($item['label'] ?? '');
            $category = $this->labelCategory($label, null);

            // Дані чека мають пріоритет; провайдер уточнює старий загальний підпис «Оплата карткою».
            $methods[] = match (true) {
                $type === 'CASH' => 'cash',
                ! in_array($type, ['CASHLESS', 'CARD'], true) => 'unknown',
                $category === 'cod_cents' => 'cod',
                (bool) preg_match('/wayforpay/iu', $label) => 'wayforpay',
                (bool) preg_match('/рахун|переказ|\bbank\b/iu', $label) => 'account',
                $category === 'account_cents' => $fallback === 'wayforpay' ? 'wayforpay' : 'account',
                default => $fallback,
            };
        }

        return array_values(array_unique($methods ?: ['unknown']));
    }

    private function orderMethod(?string $method, ?string $provider): string
    {
        if ($this->methodCategory($method) === 'cod_cents') {
            return 'cod';
        }
        if (strtolower(trim($provider ?? '')) === 'wayforpay' || strtolower(trim($method ?? '')) === 'wayforpay') {
            return 'wayforpay';
        }

        return match ($this->methodCategory($method)) {
            'account_cents' => 'account',
            default => strtolower(trim($method ?? '')) === 'cash' ? 'cash' : 'unknown',
        };
    }

    public function amounts(FiscalReceipt $receipt): array
    {
        $totals = ['account_cents' => 0, 'cod_cents' => 0, 'other_cents' => 0];
        if ($receipt->status !== FiscalReceipt::STATUS_SUCCESS
            || ! in_array($receipt->type, [FiscalReceipt::TYPE_SELL, FiscalReceipt::TYPE_RETURN], true)) {
            return $totals;
        }

        $amount = (int) $receipt->total_amount;
        $sign = $receipt->type === FiscalReceipt::TYPE_RETURN ? -1 : 1;
        $payments = $receipt->meta['payments'] ?? [];
        $method = $receipt->order?->payment?->method;

        // Для старих чеків без реквізитів використовуємо спосіб оплати замовлення.
        if (! is_array($payments) || $payments === []) {
            $totals[$this->methodCategory($method)] = $amount * $sign;

            return $totals;
        }

        $change = max(0, (int) ($receipt->meta['total_rest'] ?? 0));
        foreach ($payments as $payment) {
            $value = is_array($payment) ? ($payment['value'] ?? null) : null;
            if (! is_int($value) && ! (is_string($value) && preg_match('/^\d+$/D', $value))) {
                return ['account_cents' => 0, 'cod_cents' => 0, 'other_cents' => $amount * $sign];
            }
            if ((int) $value < 0) {
                return ['account_cents' => 0, 'cod_cents' => 0, 'other_cents' => $amount * $sign];
            }

            $value = (int) $value;
            $type = strtoupper((string) ($payment['type'] ?? ''));
            if ($type === 'CASH') {
                $rest = min($value, $change);
                $value -= $rest;
                $change -= $rest;
            }
            $category = in_array($type, ['CASHLESS', 'CARD'], true)
                ? $this->labelCategory((string) ($payment['label'] ?? ''), $method)
                : 'other_cents';
            $totals[$category] += $value;
        }

        // Неповні чи суперечливі платежі не перетворюємо на вигадану оплату карткою.
        if (array_sum($totals) !== $amount) {
            return ['account_cents' => 0, 'cod_cents' => 0, 'other_cents' => $amount * $sign];
        }

        return array_map(fn (int $value) => $value * $sign, $totals);
    }

    private function labelCategory(string $label, ?string $method): string
    {
        // Підпис збереженого чека має пріоритет над подальшими змінами замовлення.
        if (preg_match('/післяплат|накладен|наложен|nova\s*pay|новапей/iu', $label)) {
            return 'cod_cents';
        }
        if (preg_match('/карт|рахун|переказ|передоплат|wayforpay|\bcard\b|\bbank\b/iu', $label)) {
            return 'account_cents';
        }

        return $this->methodCategory($method);
    }

    private function methodCategory(?string $method): string
    {
        return match (strtolower(trim($method ?? ''))) {
            'cod', 'novapay_cod' => 'cod_cents',
            'card', 'invoice', 'transfer', 'bank_transfer', 'bank', 'cashless', 'wayforpay', 'online' => 'account_cents',
            default => 'other_cents',
        };
    }
}
