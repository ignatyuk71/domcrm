<?php

namespace App\Services\Analytics;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ShippingReturnsAnalyticsService
{
    // Запасна оцінка лише для посилок без відомої вартості за поточною ТТН.
    private const ESTIMATED_COST_UAH = 100;

    public function report(array $filters): array
    {
        $timezone = config('app.timezone', 'Europe/Kyiv');
        $start = Carbon::parse($filters['date_from'], $timezone)->startOfDay();
        $end = Carbon::parse($filters['date_to'], $timezone)->endOfDay();
        $now = Carbon::now($timezone);
        $orders = $this->orders($filters);
        $missingTracking = (clone $orders)->where(fn (Builder $q) => $q
            ->whereNull('d.ttn')->orWhereRaw("TRIM(d.ttn) = ''"))->count();

        // Час трекінгу — перша фіксація результату, а не кожне повторне опитування НП.
        $history = DB::table('order_delivery_status_histories')
            ->whereIn('status_code', ['refusal', 'received', 'received_money', 'cod_on_way'])
            ->select('order_delivery_id')
            ->selectRaw("MIN(CASE WHEN status_code = 'refusal' THEN entered_at END) as returned_at")
            ->selectRaw("MIN(CASE WHEN status_code IN ('received', 'received_money', 'cod_on_way') THEN entered_at END) as received_at")
            ->groupBy('order_delivery_id');

        $datedOrders = $orders->leftJoinSub($history, 'h', 'h.order_delivery_id', '=', 'd.id')
            ->whereNotNull('d.ttn')->whereRaw("TRIM(d.ttn) <> ''")
            ->selectRaw('d.carrier, TRIM(d.ttn) as ttn, COALESCE(s.code, o.status) as status_code')
            ->selectRaw("CASE WHEN COALESCE(s.code, o.status) = 'returned'
                THEN COALESCE(h.returned_at, o.status_changed_at)
                ELSE COALESCE(h.received_at, o.status_changed_at) END as occurred_at");

        // Одна ТТН у межах перевізника рахується один раз, навіть у дублі замовлення.
        // Повернення має пріоритет над отриманням тієї самої посилки.
        $shipments = DB::query()->fromSub($datedOrders, 'outcomes')
            ->select('carrier', 'ttn')
            ->selectRaw("MAX(CASE WHEN status_code = 'returned' THEN 1 ELSE 0 END) as is_returned")
            ->selectRaw("CASE WHEN MAX(CASE WHEN status_code = 'returned' THEN 1 ELSE 0 END) = 1
                THEN MIN(CASE WHEN status_code = 'returned' THEN occurred_at END)
                ELSE MIN(occurred_at) END as occurred_at")
            ->groupBy('carrier', 'ttn');

        $undated = DB::query()->fromSub(clone $shipments, 'shipments')->whereNull('occurred_at')->count();
        $daily = DB::query()->fromSub($shipments, 'shipments')
            ->leftJoinSub($this->costSnapshots(), 'costs', fn ($join) => $join
                ->on('costs.carrier', '=', 'shipments.carrier')->on('costs.ttn', '=', 'shipments.ttn')
                ->where('costs.snapshot_row', 1))
            ->whereBetween('occurred_at', [$start, $end->min($now)])
            ->selectRaw('DATE(occurred_at) as day, SUM(is_returned) as returned, SUM(1 - is_returned) as received')
            ->selectRaw('SUM(CASE WHEN is_returned = 1 AND costs.cost IS NOT NULL THEN 1 ELSE 0 END) as priced')
            ->selectRaw('SUM(CASE WHEN is_returned = 1 THEN COALESCE(costs.cost, 0) ELSE 0 END) as api_cost')
            ->groupByRaw('DATE(occurred_at)')->get()->keyBy('day');

        $returned = (int) $daily->sum('returned');
        $received = (int) $daily->sum('received');
        $completed = $returned + $received;
        $priced = (int) $daily->sum('priced');
        $apiCost = round((float) $daily->sum('api_cost'), 2);
        $estimatedCost = ($returned - $priced) * self::ESTIMATED_COST_UAH;
        $totalCost = round($apiCost + $estimatedCost, 2);
        $trend = ['dates' => [], 'returned' => [], 'total_cost' => [], 'api_cost' => [], 'estimated_cost' => [], 'estimated_shipments' => []];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $date = $day->toDateString();
            $row = $daily->get($date);
            $count = $date > $now->toDateString() ? null : (int) ($row->returned ?? 0);
            $dayApiCost = $count === null ? null : round((float) ($row->api_cost ?? 0), 2);
            $dayEstimated = $count === null ? null : $count - (int) ($row->priced ?? 0);
            $dayEstimatedCost = $dayEstimated === null ? null : $dayEstimated * self::ESTIMATED_COST_UAH;
            $trend['dates'][] = $date;
            $trend['returned'][] = $count;
            $trend['api_cost'][] = $dayApiCost;
            $trend['estimated_shipments'][] = $dayEstimated;
            $trend['estimated_cost'][] = $dayEstimatedCost;
            $trend['total_cost'][] = $count === null ? null : round($dayApiCost + $dayEstimatedCost, 2);
        }

        return [
            'currency' => 'UAH',
            'cost_basis' => 'api_with_fallback',
            'cost_source' => 'nova_poshta.DocumentCost',
            'estimated_cost_per_return' => self::ESTIMATED_COST_UAH,
            'totals' => [
                'returned' => $returned,
                'received' => $received,
                'completed' => $completed,
                'return_rate' => $completed > 0 ? round($returned / $completed * 100, 1) : null,
                'priced' => $priced,
                'estimated_shipments' => $returned - $priced,
                'api_cost' => $apiCost,
                'estimated_cost' => $estimatedCost,
                'total_cost' => $totalCost,
                'average_cost' => $returned > 0 ? round($totalCost / $returned, 2) : null,
            ],
            'trend' => $trend,
            // Без дати/ТТН не можна достовірно розподілити старі записи за періодами.
            'quality' => ['undated_shipments' => $undated, 'missing_tracking_orders' => $missingTracking],
        ];
    }

    private function costSnapshots(): Builder
    {
        // Збережені відповіді НП: без запитів до API під час відкриття аналітики.
        // Для повернень початковий платник не обмежує витрати; нуль є відомою ціною.
        return DB::table('order_deliveries')
            ->where('carrier', 'nova_poshta')->whereNotNull('np_cost_checked_at')
            ->whereRaw('np_cost_ttn = TRIM(ttn)')
            ->selectRaw('carrier, TRIM(ttn) as ttn')
            ->selectRaw("CASE WHEN np_document_cost >= 0 AND np_cost_status_code NOT IN ('2', '3') THEN np_document_cost END as cost")
            // Найсвіжіша відповідь по поточній ТТН, без повторного додавання дублів.
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY carrier, TRIM(ttn) ORDER BY np_cost_checked_at DESC, id DESC) as snapshot_row');
    }

    private function orders(array $filters): Builder
    {
        // «Прибуло у відділення», оплата та скасування не є фінальним результатом посилки.
        return DB::table('orders as o')
            ->leftJoin('statuses as s', 's.id', '=', 'o.status_id')
            ->leftJoin('order_deliveries as d', 'd.order_id', '=', 'o.id')
            ->whereIn(DB::raw('COALESCE(s.code, o.status)'), ['returned', 'delivered_paid'])
            ->where('o.currency', $filters['currency'])
            ->when($filters['sale_type'], fn (Builder $q, string $value) => $q->where('o.sale_type', $value))
            ->when($filters['source_id'], fn (Builder $q, int $value) => $q->where('o.source_id', $value))
            ->when($filters['manager_id'], fn (Builder $q, int $value) => $q->where('o.manager_id', $value));
    }
}
