<?php

namespace App\Services\Analytics;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SalesAnalyticsService
{
    private const INVALID_STATUSES = ['cancelled', 'canceled', 'returned'];

    public function report(array $input): array
    {
        $filters = $this->normalizeFilters($input);
        $cacheFilters = array_diff_key($filters, array_flip(['page', 'per_page', 'fresh']));
        $cacheKey = 'sales-analytics:v2:'.sha1(json_encode($cacheFilters));

        if ($filters['fresh']) {
            Cache::forget($cacheKey);
        }
        $aggregates = Cache::remember($cacheKey, 120, fn () => $this->buildAggregates($filters));

        return array_merge($aggregates, [
            'meta' => [
                'date_from' => $filters['date_from'],
                'date_to' => $filters['date_to'],
                'comparison_from' => $aggregates['comparison']['date_from'],
                'comparison_to' => $aggregates['comparison']['date_to'],
                'currency' => $filters['currency'],
                'scope' => $filters['scope'],
                'generated_at' => Carbon::now(config('app.timezone'))->toIso8601String(),
            ],
            'filters' => $this->filterOptions(),
            'audit' => $this->audit($filters),
        ]);
    }

    public function normalizeFilters(array $input): array
    {
        $timezone = config('app.timezone', 'Europe/Kyiv');
        $today = Carbon::now($timezone);
        $from = ! empty($input['date_from'])
            ? Carbon::createFromFormat('Y-m-d', $input['date_from'], $timezone)->startOfDay()
            : $today->copy()->startOfMonth();
        $to = ! empty($input['date_to'])
            ? Carbon::createFromFormat('Y-m-d', $input['date_to'], $timezone)->endOfDay()
            : $today->copy()->endOfDay();

        return [
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'currency' => strtoupper((string) ($input['currency'] ?? 'UAH')),
            'scope' => (string) ($input['scope'] ?? 'valid'),
            'sale_type' => $this->nullableString($input['sale_type'] ?? null),
            'source_id' => $this->nullableInt($input['source_id'] ?? null),
            'manager_id' => $this->nullableInt($input['manager_id'] ?? null),
            'status_id' => $this->nullableInt($input['status_id'] ?? null),
            'payment_status' => $this->nullableString($input['payment_status'] ?? null),
            'page' => max(1, (int) ($input['page'] ?? 1)),
            'per_page' => min(100, max(10, (int) ($input['per_page'] ?? 20))),
            'fresh' => filter_var($input['fresh'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    public function exportRows(array $filters): iterable
    {
        return $this->orderRowsQuery($filters)
            ->orderByDesc('o.created_at')
            ->orderByDesc('o.id')
            ->cursor();
    }

    private function buildAggregates(array $filters): array
    {
        [$rangeStart, $rangeEnd] = $this->range($filters);
        $periodDays = $rangeStart->diffInDays($rangeEnd) + 1;
        $previousEnd = $rangeStart->copy()->subSecond();
        $previousStart = $previousEnd->copy()->startOfDay()->subDays($periodDays - 1);

        $previousFilters = array_merge($filters, [
            'date_from' => $previousStart->toDateString(),
            'date_to' => $previousEnd->toDateString(),
        ]);

        $current = $this->summary($filters);
        $previous = $this->summary($previousFilters);
        $issues = $this->issueSummary($filters);
        $sources = $this->sourceBreakdown($filters, $current['revenue']);
        $products = $this->topProducts($filters);
        $saleTypes = $this->saleTypeBreakdown($filters, $current['revenue']);

        return [
            'comparison' => [
                'date_from' => $previousStart->toDateString(),
                'date_to' => $previousEnd->toDateString(),
            ],
            'kpis' => $this->kpis($current, $previous, $issues),
            'trend' => $this->trend($filters),
            'sale_types' => $saleTypes,
            'sources' => $sources,
            'top_products' => $products,
            'managers' => $this->managerBreakdown($filters, $current['revenue']),
            'statuses' => $this->statusBreakdown($filters),
            'insights' => $this->insights($current, $issues, $sources, $products, $saleTypes),
        ];
    }

    private function range(array $filters): array
    {
        $timezone = config('app.timezone', 'Europe/Kyiv');

        return [
            Carbon::createFromFormat('Y-m-d', $filters['date_from'], $timezone)->startOfDay(),
            Carbon::createFromFormat('Y-m-d', $filters['date_to'], $timezone)->endOfDay(),
        ];
    }

    private function itemTotalsQuery(): Builder
    {
        return DB::table('order_items as oi')
            ->leftJoin('products as p', 'p.id', '=', 'oi.product_id')
            ->selectRaw('oi.order_id')
            ->selectRaw('SUM(oi.total) as revenue')
            ->selectRaw('SUM(oi.qty) as units')
            ->selectRaw('SUM(CASE WHEN COALESCE(oi.cost_price, p.cost_price) IS NOT NULL THEN oi.total ELSE 0 END) as costed_revenue')
            ->selectRaw('SUM(COALESCE(oi.cost_price, p.cost_price, 0) * oi.qty) as cogs')
            ->selectRaw('SUM(CASE WHEN COALESCE(oi.cost_price, p.cost_price) IS NULL THEN 1 ELSE 0 END) as missing_cost_lines')
            ->selectRaw('COUNT(oi.id) as item_lines')
            ->groupBy('oi.order_id');
    }

    private function orderRowsQuery(array $filters, bool $ignoreScope = false, bool $ignoreStatus = false): Builder
    {
        [$rangeStart, $rangeEnd] = $this->range($filters);
        $statusExpression = 'COALESCE(st.code, o.status)';
        $profitExpression = '(COALESCE(it.costed_revenue, 0) - COALESCE(it.cogs, 0))';

        $query = DB::table('orders as o')
            ->leftJoinSub($this->itemTotalsQuery(), 'it', 'it.order_id', '=', 'o.id')
            ->leftJoin('order_sources as src', 'src.id', '=', 'o.source_id')
            ->leftJoin('users as manager', 'manager.id', '=', 'o.manager_id')
            ->leftJoin('customers as customer', 'customer.id', '=', 'o.customer_id')
            ->leftJoin('statuses as st', 'st.id', '=', 'o.status_id')
            ->whereBetween('o.created_at', [$rangeStart, $rangeEnd])
            ->where('o.currency', $filters['currency'])
            ->when($filters['sale_type'], fn (Builder $query, string $type) => $query->where('o.sale_type', $type))
            ->when($filters['source_id'], fn (Builder $query, int $id) => $query->where('o.source_id', $id))
            ->when($filters['manager_id'], fn (Builder $query, int $id) => $query->where('o.manager_id', $id))
            ->when(! $ignoreStatus && $filters['status_id'], fn (Builder $query, int $id) => $query->where('o.status_id', $id))
            ->when($filters['payment_status'], fn (Builder $query, string $status) => $query->where('o.payment_status', $status));

        // Явно вибраний статус має пріоритет над загальним режимом заліку продажів.
        if (! $ignoreScope && ! $filters['status_id']) {
            if ($filters['scope'] === 'valid') {
                $query->whereNotIn(DB::raw($statusExpression), self::INVALID_STATUSES);
            } elseif ($filters['scope'] === 'completed') {
                $query->where(function (Builder $query) use ($statusExpression) {
                    $query->whereRaw("{$statusExpression} = ?", ['delivered_paid'])
                        ->orWhere('o.payment_status', 'paid');
                });
            }
        }

        return $query->select([
            'o.id',
            'o.order_number',
            'o.created_at',
            'o.sale_type',
            'o.currency',
            'o.payment_status',
            'o.source_id',
            'o.manager_id',
            'o.status_id',
            'customer.first_name as customer_first_name',
            'customer.last_name as customer_last_name',
            'src.code as source_code',
            DB::raw('COALESCE(src.name, o.source, "Інше") as source_name'),
            'src.color as source_color',
            'manager.name as manager_name',
            DB::raw("{$statusExpression} as status_code"),
            DB::raw('COALESCE(st.name, o.status, "Без статусу") as status_name'),
            'st.color as status_color',
            DB::raw('COALESCE(it.revenue, 0) as revenue'),
            DB::raw('COALESCE(it.units, 0) as units'),
            DB::raw('COALESCE(it.costed_revenue, 0) as costed_revenue'),
            DB::raw('COALESCE(it.cogs, 0) as cogs'),
            DB::raw("{$profitExpression} as profit"),
            DB::raw('COALESCE(it.missing_cost_lines, 0) as missing_cost_lines'),
            DB::raw('COALESCE(it.item_lines, 0) as item_lines'),
        ]);
    }

    private function summary(array $filters): array
    {
        $row = DB::query()
            ->fromSub($this->orderRowsQuery($filters), 'sales')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(revenue), 0) as revenue')
            ->selectRaw('COALESCE(SUM(units), 0) as units')
            ->selectRaw('COALESCE(SUM(costed_revenue), 0) as costed_revenue')
            ->selectRaw('COALESCE(SUM(cogs), 0) as cogs')
            ->selectRaw('COALESCE(SUM(profit), 0) as profit')
            ->selectRaw('COALESCE(SUM(missing_cost_lines), 0) as missing_cost_lines')
            ->selectRaw('COALESCE(SUM(item_lines), 0) as item_lines')
            ->selectRaw('COALESCE(SUM(CASE WHEN payment_status = "paid" OR status_code = "delivered_paid" THEN revenue ELSE 0 END), 0) as paid_revenue')
            ->first();

        return [
            'orders' => (int) ($row->orders ?? 0),
            'revenue' => (float) ($row->revenue ?? 0),
            'units' => (int) ($row->units ?? 0),
            'costed_revenue' => (float) ($row->costed_revenue ?? 0),
            'cogs' => (float) ($row->cogs ?? 0),
            'profit' => (float) ($row->profit ?? 0),
            'missing_cost_lines' => (int) ($row->missing_cost_lines ?? 0),
            'item_lines' => (int) ($row->item_lines ?? 0),
            'paid_revenue' => (float) ($row->paid_revenue ?? 0),
        ];
    }

    private function issueSummary(array $filters): array
    {
        $allFilters = array_merge($filters, ['scope' => 'all', 'status_id' => null]);
        $row = DB::query()
            ->fromSub($this->orderRowsQuery($allFilters, true, true), 'sales')
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw('SUM(CASE WHEN status_code = "returned" OR payment_status = "refund" THEN 1 ELSE 0 END) as returned_orders')
            ->selectRaw('SUM(CASE WHEN status_code = "returned" OR payment_status = "refund" THEN revenue ELSE 0 END) as returned_revenue')
            ->selectRaw('SUM(CASE WHEN status_code IN ("cancelled", "canceled") THEN 1 ELSE 0 END) as cancelled_orders')
            ->selectRaw('SUM(CASE WHEN status_code IN ("cancelled", "canceled") THEN revenue ELSE 0 END) as cancelled_revenue')
            ->first();

        $total = (int) ($row->total_orders ?? 0);
        $returned = (int) ($row->returned_orders ?? 0);
        $cancelled = (int) ($row->cancelled_orders ?? 0);

        return [
            'total_orders' => $total,
            'returned_orders' => $returned,
            'returned_revenue' => (float) ($row->returned_revenue ?? 0),
            'return_rate' => $total > 0 ? round(($returned / $total) * 100, 1) : 0.0,
            'cancelled_orders' => $cancelled,
            'cancelled_revenue' => (float) ($row->cancelled_revenue ?? 0),
            'cancellation_rate' => $total > 0 ? round(($cancelled / $total) * 100, 1) : 0.0,
        ];
    }

    private function kpis(array $current, array $previous, array $issues): array
    {
        $currentMargin = $current['costed_revenue'] > 0 ? ($current['profit'] / $current['costed_revenue']) * 100 : null;
        $previousMargin = $previous['costed_revenue'] > 0 ? ($previous['profit'] / $previous['costed_revenue']) * 100 : null;
        $coverage = $current['revenue'] > 0 ? ($current['costed_revenue'] / $current['revenue']) * 100 : null;

        return [
            'revenue' => $this->metric($current['revenue'], $previous['revenue']),
            'paid_revenue' => $this->metric($current['paid_revenue'], $previous['paid_revenue']),
            'gross_profit' => $this->metric($current['profit'], $previous['profit']),
            'cogs' => $this->metric($current['cogs'], $previous['cogs']),
            'gross_margin' => [
                'value' => $currentMargin !== null ? round($currentMargin, 1) : null,
                'previous' => $previousMargin !== null ? round($previousMargin, 1) : null,
                'delta_pp' => $currentMargin !== null && $previousMargin !== null ? round($currentMargin - $previousMargin, 1) : null,
            ],
            'orders' => $this->metric($current['orders'], $previous['orders'], 0),
            'units' => $this->metric($current['units'], $previous['units'], 0),
            'average_check' => $this->metric(
                $current['orders'] > 0 ? $current['revenue'] / $current['orders'] : 0,
                $previous['orders'] > 0 ? $previous['revenue'] / $previous['orders'] : 0
            ),
            'cost_coverage' => [
                'value' => $coverage !== null ? round($coverage, 1) : null,
                'missing_lines' => $current['missing_cost_lines'],
                'total_lines' => $current['item_lines'],
            ],
            'returns' => [
                'count' => $issues['returned_orders'],
                'revenue' => round($issues['returned_revenue'], 2),
                'rate' => $issues['return_rate'],
            ],
            'cancellations' => [
                'count' => $issues['cancelled_orders'],
                'revenue' => round($issues['cancelled_revenue'], 2),
                'rate' => $issues['cancellation_rate'],
            ],
        ];
    }

    private function metric(float|int $value, float|int $previous, int $precision = 2): array
    {
        return [
            'value' => round($value, $precision),
            'previous' => round($previous, $precision),
            'delta' => $previous != 0 ? round((($value - $previous) / abs($previous)) * 100, 1) : null,
        ];
    }

    private function trend(array $filters): array
    {
        [$rangeStart, $rangeEnd] = $this->range($filters);
        $rows = DB::query()
            ->fromSub($this->orderRowsQuery($filters), 'sales')
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('SUM(revenue) as revenue')
            ->selectRaw('SUM(cogs) as cogs')
            ->selectRaw('SUM(profit) as profit')
            ->selectRaw('COUNT(*) as orders')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $labels = $revenue = $cogs = $profit = $orders = [];
        for ($cursor = $rangeStart->copy()->startOfDay(); $cursor->lte($rangeEnd); $cursor->addDay()) {
            $day = $cursor->toDateString();
            $row = $rows->get($day);
            $labels[] = $cursor->format('d.m');
            $revenue[] = round((float) ($row->revenue ?? 0), 2);
            $cogs[] = round((float) ($row->cogs ?? 0), 2);
            $profit[] = round((float) ($row->profit ?? 0), 2);
            $orders[] = (int) ($row->orders ?? 0);
        }

        return compact('labels', 'revenue', 'cogs', 'profit', 'orders');
    }

    private function sourceBreakdown(array $filters, float $totalRevenue): array
    {
        $totalRevenue = max(0.0, $totalRevenue);

        return DB::query()
            ->fromSub($this->orderRowsQuery($filters), 'sales')
            ->select(['source_id', 'source_code', 'source_name', 'source_color'])
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('SUM(units) as units')
            ->selectRaw('SUM(revenue) as revenue')
            ->selectRaw('SUM(cogs) as cogs')
            ->selectRaw('SUM(profit) as profit')
            ->selectRaw('SUM(costed_revenue) as costed_revenue')
            ->groupBy('source_id', 'source_code', 'source_name', 'source_color')
            ->orderByDesc('revenue')
            ->get()
            ->map(function ($row) use ($totalRevenue) {
                return array_merge($this->breakdownRow($row, $totalRevenue), [
                    'id' => $row->source_id,
                    'code' => $row->source_code,
                    'source_name' => $row->source_name,
                    'color' => $row->source_color ?: '#6366f1',
                ]);
            })
            ->all();
    }

    private function saleTypeBreakdown(array $filters, float $totalRevenue): array
    {
        $totalRevenue = max(0.0, $totalRevenue);

        return DB::query()
            ->fromSub($this->orderRowsQuery($filters), 'sales')
            ->select('sale_type')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('SUM(units) as units')
            ->selectRaw('SUM(revenue) as revenue')
            ->selectRaw('SUM(cogs) as cogs')
            ->selectRaw('SUM(profit) as profit')
            ->selectRaw('SUM(costed_revenue) as costed_revenue')
            ->groupBy('sale_type')
            ->orderByDesc('revenue')
            ->get()
            ->map(function ($row) use ($totalRevenue) {
                $data = $this->breakdownRow($row, $totalRevenue);
                $data['key'] = $row->sale_type ?: 'retail';
                $data['label'] = $data['key'] === 'wholesale' ? 'Опт' : 'Роздріб';

                return $data;
            })
            ->all();
    }

    private function managerBreakdown(array $filters, float $totalRevenue): array
    {
        $totalRevenue = max(0.0, $totalRevenue);

        return DB::query()
            ->fromSub($this->orderRowsQuery($filters), 'sales')
            ->select(['manager_id', 'manager_name'])
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('SUM(units) as units')
            ->selectRaw('SUM(revenue) as revenue')
            ->selectRaw('SUM(cogs) as cogs')
            ->selectRaw('SUM(profit) as profit')
            ->selectRaw('SUM(costed_revenue) as costed_revenue')
            ->groupBy('manager_id', 'manager_name')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get()
            ->map(function ($row) use ($totalRevenue) {
                $data = $this->breakdownRow($row, $totalRevenue);
                $data['id'] = $row->manager_id;
                $data['name'] = $row->manager_name ?: 'Без менеджера';

                return $data;
            })
            ->all();
    }

    private function statusBreakdown(array $filters): array
    {
        $allFilters = array_merge($filters, ['scope' => 'all', 'status_id' => null]);

        return DB::query()
            ->fromSub($this->orderRowsQuery($allFilters, true, true), 'sales')
            ->select(['status_id', 'status_code', 'status_name', 'status_color'])
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('SUM(revenue) as revenue')
            ->groupBy('status_id', 'status_code', 'status_name', 'status_color')
            ->orderByDesc('orders')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->status_id,
                'code' => $row->status_code,
                'name' => $row->status_name,
                'color' => $row->status_color ?: '#64748b',
                'orders' => (int) $row->orders,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->all();
    }

    private function topProducts(array $filters): array
    {
        $filteredOrders = DB::query()
            ->fromSub($this->orderRowsQuery($filters), 'sales')
            ->select('id');

        return DB::table('order_items as oi')
            ->joinSub($filteredOrders, 'filtered_orders', 'filtered_orders.id', '=', 'oi.order_id')
            ->leftJoin('products as p', 'p.id', '=', 'oi.product_id')
            ->selectRaw('COALESCE(p.id, 0) as product_id')
            ->selectRaw('COALESCE(p.title, oi.product_title, "Товар") as title')
            ->selectRaw('MAX(COALESCE(p.sku, oi.sku)) as sku')
            ->selectRaw('COUNT(DISTINCT oi.order_id) as orders')
            ->selectRaw('SUM(oi.qty) as units')
            ->selectRaw('SUM(oi.total) as revenue')
            ->selectRaw('SUM(CASE WHEN COALESCE(oi.cost_price, p.cost_price) IS NOT NULL THEN oi.total ELSE 0 END) as costed_revenue')
            ->selectRaw('SUM(COALESCE(oi.cost_price, p.cost_price, 0) * oi.qty) as cogs')
            ->selectRaw('SUM(CASE WHEN COALESCE(oi.cost_price, p.cost_price) IS NULL THEN 1 ELSE 0 END) as missing_cost_lines')
            ->groupByRaw('COALESCE(p.id, 0), COALESCE(p.title, oi.product_title, "Товар")')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                $profit = (float) $row->costed_revenue - (float) $row->cogs;

                return [
                    'id' => (int) $row->product_id ?: null,
                    'title' => $row->title,
                    'sku' => $row->sku,
                    'orders' => (int) $row->orders,
                    'units' => (int) $row->units,
                    'revenue' => round((float) $row->revenue, 2),
                    'cogs' => round((float) $row->cogs, 2),
                    'profit' => round($profit, 2),
                    'margin' => (float) $row->costed_revenue > 0 ? round(($profit / (float) $row->costed_revenue) * 100, 1) : null,
                    'has_missing_cost' => (int) $row->missing_cost_lines > 0,
                ];
            })
            ->all();
    }

    private function breakdownRow(object $row, float $totalRevenue): array
    {
        $revenue = (float) $row->revenue;
        $costedRevenue = (float) $row->costed_revenue;
        $profit = (float) $row->profit;

        return [
            'orders' => (int) $row->orders,
            'units' => (int) $row->units,
            'revenue' => round($revenue, 2),
            'cogs' => round((float) $row->cogs, 2),
            'profit' => round($profit, 2),
            'margin' => $costedRevenue > 0 ? round(($profit / $costedRevenue) * 100, 1) : null,
            'share' => $totalRevenue > 0 ? round(($revenue / $totalRevenue) * 100, 1) : 0.0,
        ];
    }

    private function insights(array $summary, array $issues, array $sources, array $products, array $saleTypes): array
    {
        if ($summary['orders'] === 0) {
            return [[
                'type' => 'info',
                'icon' => 'bi-calendar2-x',
                'title' => 'За вибраний період немає продажів',
                'description' => 'Змініть період або послабте фільтри.',
            ]];
        }

        $insights = [];
        if (! empty($sources[0])) {
            $insights[] = [
                'type' => 'positive',
                'icon' => 'bi-trophy',
                'title' => 'Лідер за джерелами — '.$sources[0]['source_name'],
                'description' => $sources[0]['share'].'% виторгу, '.$sources[0]['orders'].' замовлень.',
            ];
        }

        $coverage = $summary['revenue'] > 0 ? ($summary['costed_revenue'] / $summary['revenue']) * 100 : 100;
        if ($coverage < 99.9) {
            $insights[] = [
                'type' => 'warning',
                'icon' => 'bi-exclamation-triangle',
                'title' => 'Не всю собівартість заповнено',
                'description' => 'Маржа охоплює '.round($coverage, 1).'% виторгу. Заповніть закупівельні ціни товарів.',
            ];
        } elseif (! empty($products[0])) {
            $insights[] = [
                'type' => 'info',
                'icon' => 'bi-box-seam',
                'title' => 'Найбільше виторгу дає '.$products[0]['title'],
                'description' => $products[0]['units'].' од. продано, маржа '.($products[0]['margin'] ?? 0).'%.',
            ];
        }

        $insights[] = [
            'type' => $issues['return_rate'] > 10 ? 'warning' : 'neutral',
            'icon' => 'bi-arrow-return-left',
            'title' => 'Повернення — '.$issues['return_rate'].'%',
            'description' => $issues['returned_orders'].' із '.$issues['total_orders'].' замовлень за період.',
        ];

        if (count($saleTypes) === 1 && ($saleTypes[0]['key'] ?? null) === 'retail') {
            $insights[] = [
                'type' => 'info',
                'icon' => 'bi-tags',
                'title' => 'Оптові продажі ще не позначені',
                'description' => 'Тип продажу можна вибрати у параметрах кожного замовлення.',
            ];
        }

        return array_slice($insights, 0, 4);
    }

    private function audit(array $filters): array
    {
        /** @var LengthAwarePaginator $paginator */
        $paginator = $this->orderRowsQuery($filters)
            ->orderByDesc('o.created_at')
            ->orderByDesc('o.id')
            ->paginate($filters['per_page'], ['*'], 'page', $filters['page']);

        $paginator->setCollection($paginator->getCollection()->map(function ($row) {
            $costedRevenue = (float) $row->costed_revenue;

            return [
                'id' => (int) $row->id,
                'number' => $row->order_number,
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
                'customer' => trim(($row->customer_first_name ?? '').' '.($row->customer_last_name ?? '')) ?: 'Клієнт',
                'sale_type' => $row->sale_type ?: 'retail',
                'currency' => $row->currency,
                'payment_status' => $row->payment_status,
                'source' => ['code' => $row->source_code, 'name' => $row->source_name, 'color' => $row->source_color],
                'manager' => $row->manager_name ?: 'Без менеджера',
                'status' => ['code' => $row->status_code, 'name' => $row->status_name, 'color' => $row->status_color],
                'orders' => 1,
                'units' => (int) $row->units,
                'revenue' => round((float) $row->revenue, 2),
                'cogs' => round((float) $row->cogs, 2),
                'profit' => round((float) $row->profit, 2),
                'margin' => $costedRevenue > 0 ? round(((float) $row->profit / $costedRevenue) * 100, 1) : null,
                'has_missing_cost' => (int) $row->missing_cost_lines > 0,
            ];
        }));

        return $paginator->toArray();
    }

    private function filterOptions(): array
    {
        $currencies = DB::table('orders')->whereNotNull('currency')->distinct()->orderBy('currency')->pluck('currency')->all();
        if (! in_array('UAH', $currencies, true)) {
            array_unshift($currencies, 'UAH');
        }

        return [
            'sources' => DB::table('order_sources')->orderBy('sort_order')->orderBy('name')->get(['id', 'code', 'name', 'color'])->all(),
            'managers' => DB::table('users')->whereIn('id', DB::table('orders')->whereNotNull('manager_id')->select('manager_id'))->orderBy('name')->get(['id', 'name'])->all(),
            'statuses' => DB::table('statuses')->where('type', 'order')->orderBy('sort_order')->get(['id', 'code', 'name', 'color'])->all(),
            'currencies' => $currencies,
            'sale_types' => [
                ['value' => 'retail', 'label' => 'Роздріб'],
                ['value' => 'wholesale', 'label' => 'Опт'],
            ],
            'scopes' => [
                ['value' => 'valid', 'label' => 'Без скасованих і повернень'],
                ['value' => 'completed', 'label' => 'Лише завершені / оплачені'],
                ['value' => 'all', 'label' => 'Усі замовлення'],
            ],
            'payment_statuses' => [
                ['value' => 'unpaid', 'label' => 'Не оплачено'],
                ['value' => 'prepayment', 'label' => 'Передоплата'],
                ['value' => 'paid', 'label' => 'Оплачено'],
                ['value' => 'refund', 'label' => 'Повернення'],
            ],
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = (int) ($value ?? 0);

        return $value > 0 ? $value : null;
    }
}
