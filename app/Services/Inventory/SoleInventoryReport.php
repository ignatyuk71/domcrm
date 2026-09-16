<?php

namespace App\Services\Inventory;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SoleInventoryReport
{
    private const DELIVERY_EVIDENCE = ['in_transit', 'at_warehouse', 'received', 'received_money', 'cod_on_way', 'refusal'];

    private const ORDER_EVIDENCE = ['shipped', 'delivered', 'delivered_paid', 'returned'];

    public function __construct(private SoleInventoryCatalog $catalog) {}

    public function build(): array
    {
        $now = CarbonImmutable::now(config('app.timezone'));
        $today = $now->startOfDay();
        $categories = $this->catalog->categories()->keyBy('id');
        $plans = DB::table('sole_inventory_plans')->whereIn('category_id', $categories->keys())->get()->keyBy('category_id');
        $movements = DB::table('sole_inventory_movements')->whereIn('plan_id', $plans->pluck('id'))->where('movement_date', '<=', $today->toDateString())
            ->selectRaw('plan_id, size, SUM(quantity) as quantity')->groupBy('plan_id', 'size')->get()->groupBy('plan_id');
        $report = [];
        foreach ($categories as $category) {
            $plan = $plans->get($category->id);
            $window = (int) ($plan->lookback_days ?? 30);
            $balances = collect($plan ? json_decode($plan->opening_balances, true) : [])->keyBy('size');
            $rows = [];
            foreach ($this->catalog->sizes($category) as $size) {
                $initial = $balances->get($size)['quantity'] ?? null;
                $rows[$size] = [
                    'size' => $size, 'opening_quantity' => $initial === null ? null : (int) $initial,
                    'movements_quantity' => (int) ($movements->get($plan->id ?? 0, collect())->firstWhere('size', $size)->quantity ?? 0),
                    'consumed' => 0, 'shipped_in_window' => 0,
                ];
            }
            $start = $today->subDays($window)->toDateString();
            $trend = [];
            for ($i = $window; $i > 0; $i--) {
                $trend[$today->subDays($i)->toDateString()] = 0;
            }
            $report[$category->id] = [
                'id' => $category->id, 'name' => $category->name,
                'settings' => ['opening_date' => $plan->opening_date ?? null, 'version' => (int) ($plan->version ?? 0),
                    'lead_time_days' => isset($plan->lead_time_days) ? (int) $plan->lead_time_days : null,
                    'safety_days' => (int) ($plan->safety_days ?? 14), 'lookback_days' => $window],
                'window_from' => $start, 'window_to' => $today->subDay()->toDateString(),
                'rows' => $rows, 'trend' => $trend, 'recent_shipments' => [],
                'warnings' => ['unknown_size_pairs' => 0, 'missing_date_orders' => 0, 'duplicate_orders' => 0, 'approximate_date_pairs' => 0, 'without_ttn_orders' => 0],
                'issues' => [],
            ];
        }

        // Одна накладна не списується повторно через зміну статусу або дублі замовлень.
        $seenTtn = [];
        $warned = [];
        foreach ($this->shipmentRows($categories->keys()->all())->cursor() as $item) {
            $category = $categories[$item->category_id];
            $group = &$report[$item->category_id];
            $dates = array_filter([$item->history_at, $item->audit_at]);
            $at = $dates ? min($dates) : (in_array($item->current_status, self::ORDER_EVIDENCE, true) ? $item->status_changed_at : null);
            $hasEvidence = $dates || in_array($item->current_status, self::ORDER_EVIDENCE, true) || in_array($item->delivery_status_code, self::DELIVERY_EVIDENCE, true);
            if (! $at) {
                if ($hasEvidence) {
                    $this->warnOrder($group, $warned, $item, 'missing_date_orders', 'Немає дати відправлення в історії.');
                }

                continue;
            }
            if ($at > $now->format('Y-m-d H:i:s.u')) {
                continue;
            }
            $date = substr($at, 0, 10);
            $ttn = trim((string) $item->ttn);
            $shipmentKey = strtolower(trim((string) $item->carrier)).':'.$ttn;
            if ($ttn !== '') {
                if (isset($seenTtn[$shipmentKey]) && $seenTtn[$shipmentKey] !== (int) $item->order_id) {
                    if ($date >= min($group['window_from'], $group['settings']['opening_date'] ?? $group['window_from'])) {
                        $this->warnOrder($group, $warned, $item, 'duplicate_orders', 'Дубль ТТН: враховано лише перше замовлення.');
                    }

                    continue;
                }
                $seenTtn[$shipmentKey] = (int) $item->order_id;
            }
            if ($date < min($group['window_from'], $group['settings']['opening_date'] ?? $group['window_from'])) {
                continue;
            }

            $size = $this->catalog->normalizeSize($item->size ?: $item->variant_size, $category);
            $qty = (int) $item->qty;
            if (! $size) {
                $group['warnings']['unknown_size_pairs'] += $qty;
                $this->issue($group, $item, 'Невідомий розмір: '.($item->size ?: $item->variant_size ?: 'не вказано'));

                continue;
            }
            if ($ttn === '') {
                $this->warnOrder($group, $warned, $item, 'without_ttn_orders', 'Без ТТН: враховано за історією статусу CRM.');
            }

            $directDates = array_filter([$item->dispatch_at, $item->audit_dispatch_at]);
            $approximate = ! $directDates || min($directDates) !== $at;
            if ($approximate) {
                $group['warnings']['approximate_date_pairs'] += $qty;
            }
            if ($group['settings']['opening_date'] && $date >= $group['settings']['opening_date']) {
                $group['rows'][$size]['consumed'] += $qty;
            }
            if ($date >= $group['window_from'] && $date < $today->toDateString()) {
                $group['rows'][$size]['shipped_in_window'] += $qty;
                $group['trend'][$date] += $qty;
            }
            $group['recent_shipments'][] = ['order_id' => (int) $item->order_id, 'order_number' => $item->order_number,
                'size' => $size, 'quantity' => $qty, 'date' => $date, 'approximate_date' => $approximate];
            if (count($group['recent_shipments']) > 40) {
                // Не накопичуємо всі позиції багаторічної історії для короткого списку на сторінці.
                $group['recent_shipments'] = collect($group['recent_shipments'])->sortByDesc('date')->take(20)->values()->all();
            }
        }
        unset($group);

        $journal = DB::table('sole_inventory_movements as m')->join('sole_inventory_plans as p', 'p.id', '=', 'm.plan_id')
            ->whereIn('p.category_id', $categories->keys())->orderByDesc('m.id')->limit(30)
            ->get(['m.id', 'p.category_id', 'm.size', 'm.kind', 'm.quantity', 'm.movement_date', 'm.note']);
        $totals = ['remaining' => 0, 'configured_sizes' => 0, 'total_sizes' => 0, 'order_now' => 0];
        foreach ($report as &$group) {
            foreach ($group['rows'] as &$row) {
                $row = $this->forecast($row, $group['settings'], $today);
                $totals['total_sizes']++;
                if ($row['remaining'] !== null) {
                    $totals['configured_sizes']++;
                    $totals['remaining'] += $row['remaining'];
                }
                if (in_array($row['status'], ['order_now', 'depleted'], true)) {
                    $totals['order_now']++;
                }
            }
            unset($row);
            $group['rows'] = array_values($group['rows']);
            $group['trend'] = collect($group['trend'])->map(fn ($qty, $date) => ['date' => $date, 'quantity' => $qty])->values()->all();
            $group['recent_shipments'] = collect($group['recent_shipments'])->sortByDesc('date')->take(20)->values()->all();
        }
        unset($group);

        return ['as_of' => $now->toIso8601String(), 'today' => $today->toDateString(), 'categories' => array_values($report),
            'totals' => $totals, 'journal' => $journal,
            'missing_categories' => array_values(array_diff(array_keys(SoleInventoryCatalog::CATEGORIES), $categories->pluck('name')->all())),
            'unlinked_item_count' => DB::table('order_items as i')->leftJoin('products as p', 'p.id', '=', 'i.product_id')->whereNull('p.id')->count(),
            'history_available_from' => DB::table('order_delivery_status_histories')->whereIn('status_code', self::DELIVERY_EVIDENCE)->min('entered_at'),
        ];
    }

    private function shipmentRows(array $categoryIds)
    {
        $history = DB::table('order_delivery_status_histories')->whereIn('status_code', self::DELIVERY_EVIDENCE)
            ->where(fn ($q) => $q->whereNull('source_code')->orWhere('source_code', '<>', '104'))
            ->selectRaw("order_delivery_id, MIN(entered_at) as history_at, MIN(CASE WHEN status_code = 'in_transit' THEN entered_at END) as dispatch_at")
            ->groupBy('order_delivery_id');
        $audit = DB::table('order_status_changes')->whereIn('new_status', self::ORDER_EVIDENCE)
            ->selectRaw("order_id, MIN(occurred_at) as audit_at, MIN(CASE WHEN new_status = 'shipped' THEN occurred_at END) as audit_dispatch_at")
            ->groupBy('order_id');

        return DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->leftJoin('product_variants as v', fn ($join) => $join->on('v.id', '=', 'i.product_variant_id')->on('v.product_id', '=', 'p.id'))
            ->leftJoin('statuses as s', 's.id', '=', 'o.status_id')
            ->leftJoin('order_deliveries as d', 'd.order_id', '=', 'o.id')
            ->leftJoinSub($history, 'h', 'h.order_delivery_id', '=', 'd.id')
            ->leftJoinSub($audit, 'a', 'a.order_id', '=', 'o.id')
            ->whereIn('p.category_id', $categoryIds)->where('i.qty', '>', 0)
            ->orderBy('o.id')->orderBy('i.id')
            ->select(['i.order_id', 'i.size', 'i.qty', 'p.category_id', 'v.size as variant_size', 'o.order_number', 'o.status_changed_at',
                'd.ttn', 'd.carrier', 'd.delivery_status_code', 'h.history_at', 'h.dispatch_at', 'a.audit_at', 'a.audit_dispatch_at'])
            ->selectRaw('COALESCE(s.code, o.status) as current_status');
    }

    private function forecast(array $row, array $settings, CarbonImmutable $today): array
    {
        $remaining = $row['opening_quantity'] === null ? null : $row['opening_quantity'] + $row['movements_quantity'] - $row['consumed'];
        $rate = $row['shipped_in_window'] / $settings['lookback_days'];
        $days = $remaining === null || $rate <= 0 ? null : max(0, (int) floor($remaining / $rate));
        // Нульовий залишок — дефіцит навіть без історії продажів; нульовий темп не означає безкінечний запас.
        $depletion = $remaining !== null && $remaining <= 0 ? $today : ($days === null ? null : $today->addDays(min($days, 36500)));
        $lead = $settings['lead_time_days'];
        $reorder = $depletion && $lead !== null ? $depletion->subDays($lead + $settings['safety_days']) : null;
        $status = match (true) {
            $remaining === null => 'unconfigured',
            $remaining <= 0 => 'depleted',
            $rate <= 0 => 'no_history',
            $lead === null => 'missing_lead',
            $days <= $lead + $settings['safety_days'] => 'order_now',
            default => 'sufficient',
        };

        return $row + ['remaining' => $remaining, 'daily_rate' => round($rate, 2), 'days_remaining' => $days,
            'depletion_date' => $days !== null && $days > 36500 ? null : $depletion?->toDateString(),
            'reorder_date' => $days !== null && $days > 36500 ? null : $reorder?->toDateString(),
            'reorder_point' => $lead === null ? null : (int) ceil($rate * ($lead + $settings['safety_days'])), 'status' => $status];
    }

    private function warnOrder(array &$group, array &$warned, object $item, string $type, string $message): void
    {
        $key = $item->category_id.':'.$type.':'.$item->order_id;
        if (! isset($warned[$key])) {
            $warned[$key] = true;
            $group['warnings'][$type]++;
            if ($type !== 'without_ttn_orders') {
                $this->issue($group, $item, $message);
            }
        }
    }

    private function issue(array &$group, object $item, string $message): void
    {
        if (count($group['issues']) < 10) {
            $group['issues'][] = ['order_id' => (int) $item->order_id, 'order_number' => $item->order_number, 'message' => $message];
        }
    }
}
