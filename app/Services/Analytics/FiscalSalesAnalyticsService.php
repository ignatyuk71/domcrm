<?php

namespace App\Services\Analytics;

use App\Models\FiscalReceipt;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FiscalSalesAnalyticsService
{
    public function report(array $filters, array $previousFilters): array
    {
        $current = $this->period($filters);
        $previous = $this->period($previousFilters);
        $kpis = [];
        foreach (['revenue', 'receipts', 'average_check'] as $key) {
            $value = $current['totals'][$key];
            $before = $previous['totals'][$key];
            $kpis[$key] = [
                'value' => $value,
                'previous' => $before,
                'delta' => $before != 0 ? round(($value - $before) / abs($before) * 100, 1) : null,
            ];
        }

        return array_merge($current, ['kpis' => $kpis, 'currency' => 'UAH']);
    }

    private function period(array $filters): array
    {
        $start = Carbon::parse($filters['date_from'], config('app.timezone'))->startOfDay();
        $end = Carbon::parse($filters['date_to'], config('app.timezone'))->endOfDay();
        $empty = ['sales' => 0, 'refunds' => 0, 'receipts' => 0, 'refund_receipts' => 0, 'cash' => 0, 'cashless' => 0, 'other' => 0];
        $totals = $empty;
        $days = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $days[$day->toDateString()] = $empty;
        }
        $fallbackDates = 0;
        $unknownPayments = 0;

        // Статуси замовлень не переписують історію продажу й повернення коштів.
        $query = DB::table('fiscal_receipts as fr')->join('orders as o', 'o.id', '=', 'fr.order_id')
            ->where('fr.status', FiscalReceipt::STATUS_SUCCESS)
            ->whereIn('fr.type', [FiscalReceipt::TYPE_SELL, FiscalReceipt::TYPE_RETURN])
            ->whereNotNull('fr.fiscal_code')->where('fr.fiscal_code', '!=', '')
            ->where(fn ($q) => $q->whereBetween('fr.fiscalized_at', [$start, $end])->orWhereNull('fr.fiscalized_at'))
            ->where('o.currency', 'UAH')
            ->when($filters['currency'] !== 'UAH', fn ($q) => $q->whereRaw('1 = 0'))
            ->when($filters['sale_type'], fn ($q, $type) => $q->where('o.sale_type', $type))
            ->when($filters['source_id'], fn ($q, $id) => $q->where('o.source_id', $id))
            ->when($filters['manager_id'], fn ($q, $id) => $q->where('o.manager_id', $id))
            ->select(['fr.id', 'fr.type', 'fr.total_amount', 'fr.fiscalized_at', 'fr.created_at', 'fr.meta']);

        foreach ($query->lazyById(500, 'fr.id', 'id') as $receipt) {
            $meta = json_decode($receipt->meta ?? '{}', true) ?: [];
            if (filter_var($meta['is_test'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }
            $metaDate = FiscalReceipt::dateFromMeta($meta);
            // Чеки, записані старим процесом під час деплою, теж не втрачаємо.
            $occurredAt = $receipt->fiscalized_at
                ? Carbon::parse($receipt->fiscalized_at, config('app.timezone'))
                : ($metaDate ?? Carbon::parse($receipt->created_at, config('app.timezone')));
            if ($occurredAt->lt($start) || $occurredAt->gt($end)) {
                continue;
            }
            $fallbackDates += $metaDate === null ? 1 : 0;
            $amount = isset($meta['total_sum']) && is_numeric($meta['total_sum'])
                ? abs((int) $meta['total_sum']) : (int) $receipt->total_amount;
            $refund = $receipt->type === FiscalReceipt::TYPE_RETURN;
            $sign = $refund ? -1 : 1;
            $date = $occurredAt->toDateString();
            $payments = $this->payments($meta, $amount);
            $unknownPayments += $payments['other'] !== 0 ? 1 : 0;
            $event = $empty;
            $event[$refund ? 'refunds' : 'sales'] = $amount;
            $event[$refund ? 'refund_receipts' : 'receipts'] = 1;
            foreach ($payments as $key => $value) {
                $event[$key] = $sign * $value;
            }
            foreach ($event as $key => $value) {
                $totals[$key] += $value;
                $days[$date][$key] += $value;
            }
        }

        $trend = ['dates' => [], 'labels' => [], 'revenue' => [], 'cash' => [], 'cashless' => [], 'other' => [], 'receipts' => [], 'average_check' => [], 'refunds' => []];
        $today = now(config('app.timezone'))->toDateString();
        foreach ($days as $date => $values) {
            $values = $this->moneyValues($values);
            $trend['dates'][] = $date;
            $trend['labels'][] = Carbon::parse($date)->format('d.m');
            foreach (['revenue', 'cash', 'cashless', 'other', 'receipts', 'average_check', 'refunds'] as $key) {
                // Майбутні дні — немає даних, а не падіння продажів до нуля.
                $trend[$key][] = $date > $today ? null : $values[$key];
            }
        }

        return [
            'totals' => $this->moneyValues($totals),
            'trend' => $trend,
            'quality' => ['fallback_date_receipts' => $fallbackDates, 'unknown_payment_receipts' => $unknownPayments],
        ];
    }

    private function moneyValues(array $values): array
    {
        $values['revenue'] = ($values['sales'] - $values['refunds']) / 100;
        $values['average_check'] = $values['receipts'] > 0 ? round($values['sales'] / $values['receipts'] / 100, 2) : 0;
        foreach (['sales', 'refunds', 'cash', 'cashless', 'other'] as $key) {
            $values[$key] /= 100;
        }

        return $values;
    }

    private function payments(array $meta, int $amount): array
    {
        $values = ['cash' => 0, 'cashless' => 0, 'other' => 0];
        foreach ($meta['payments'] ?? [] as $payment) {
            $key = match ($payment['type'] ?? '') {
                'CASH' => 'cash',
                'CASHLESS' => 'cashless',
                default => 'other',
            };
            $values[$key] += abs((int) ($payment['value'] ?? 0));
        }
        $values['cash'] -= min($values['cash'], max(0, (int) ($meta['total_rest'] ?? 0)));

        // Неповні дані не перетворюємо на вигадану оплату карткою чи готівкою.
        return array_sum($values) === $amount ? $values : ['cash' => 0, 'cashless' => 0, 'other' => $amount];
    }
}
