<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Сторінка дашборду (Vue монтується у blade).
     */
    public function index()
    {
        return view('dashboard');
    }

    /**
     * JSON-дані для дашборду: денні серії, KPI, останні замовлення.
     */
    public function data(Request $request): JsonResponse
    {
        $days = (int) $request->integer('days', 30);
        if (!in_array($days, [7, 30, 90], true)) {
            $days = 30;
        }

        $tz = config('app.timezone', 'Europe/Kyiv');
        $today = Carbon::now($tz)->startOfDay();
        $start = $today->copy()->subDays($days - 1);
        $rangeStart = $start->copy()->startOfDay();
        $rangeEnd = $today->copy()->endOfDay();

        $series = $this->buildSeries($rangeStart, $rangeEnd, $start, $today);
        $kpis = $this->buildKpis($rangeStart, $rangeEnd, $today, $tz, $series);
        $recent = $this->recentOrders();
        $topProducts = $this->topProducts($rangeStart, $rangeEnd);
        $sourceBreakdown = $this->sourceBreakdown($rangeStart, $rangeEnd);

        return response()->json([
            'days' => $days,
            'series' => $series,
            'kpis' => $kpis,
            'recent_orders' => $recent,
            'top_products' => $topProducts,
            'source_breakdown' => $sourceBreakdown,
            'generated_at' => Carbon::now($tz)->toDateTimeString(),
        ]);
    }

    /**
     * Денні серії: створені замовлення, відправлені, виторг.
     */
    private function buildSeries(Carbon $rangeStart, Carbon $rangeEnd, Carbon $start, Carbon $today): array
    {
        // Створені замовлення по днях
        $createdByDate = Order::query()
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd');

        // Виторг по днях (сума позицій за датою замовлення)
        $revenueByDate = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereBetween('orders.created_at', [$rangeStart, $rangeEnd])
            ->selectRaw('DATE(orders.created_at) as d, SUM(order_items.total) as total')
            ->groupBy('d')
            ->pluck('total', 'd');

        // Відправлені по днях — перша поява статусу in_transit для кожної доставки
        $shippedByDate = DB::table(DB::raw('(
                SELECT order_delivery_id, MIN(entered_at) as first_shipped
                FROM order_delivery_status_histories
                WHERE status_code = "in_transit"
                GROUP BY order_delivery_id
            ) as t'))
            ->whereBetween('first_shipped', [$rangeStart, $rangeEnd])
            ->selectRaw('DATE(first_shipped) as d, COUNT(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd');

        // Сума ВІДПРАВЛЕНИХ посилок по днях (на скільки відправили)
        $shippedValueByDate = DB::table(DB::raw('(
                SELECT order_delivery_id, MIN(entered_at) as first_shipped
                FROM order_delivery_status_histories
                WHERE status_code = "in_transit"
                GROUP BY order_delivery_id
            ) as t'))
            ->join('order_deliveries as od', 'od.id', '=', 't.order_delivery_id')
            ->join(DB::raw('(SELECT order_id, SUM(total) as total FROM order_items GROUP BY order_id) as oi'), 'oi.order_id', '=', 'od.order_id')
            ->whereBetween('t.first_shipped', [$rangeStart, $rangeEnd])
            ->selectRaw('DATE(t.first_shipped) as d, SUM(oi.total) as total')
            ->groupBy('d')
            ->pluck('total', 'd');

        // Сума ОТРИМАНИХ посилок по днях (на скільки забрали / гроші прийшли)
        $receivedValueByDate = DB::table(DB::raw('(
                SELECT order_delivery_id, MIN(entered_at) as first_received
                FROM order_delivery_status_histories
                WHERE status_code IN ("received", "received_money")
                GROUP BY order_delivery_id
            ) as t'))
            ->join('order_deliveries as od', 'od.id', '=', 't.order_delivery_id')
            ->join(DB::raw('(SELECT order_id, SUM(total) as total FROM order_items GROUP BY order_id) as oi'), 'oi.order_id', '=', 'od.order_id')
            ->whereBetween('t.first_received', [$rangeStart, $rangeEnd])
            ->selectRaw('DATE(t.first_received) as d, SUM(oi.total) as total')
            ->groupBy('d')
            ->pluck('total', 'd');

        $labels = [];
        $created = [];
        $shipped = [];
        $revenue = [];
        $shippedValue = [];
        $receivedValue = [];

        $cursor = $start->copy();
        while ($cursor->lte($today)) {
            $key = $cursor->toDateString();
            $labels[] = $cursor->format('d.m');
            $created[] = (int) ($createdByDate[$key] ?? 0);
            $shipped[] = (int) ($shippedByDate[$key] ?? 0);
            $revenue[] = round((float) ($revenueByDate[$key] ?? 0), 2);
            $shippedValue[] = round((float) ($shippedValueByDate[$key] ?? 0), 2);
            $receivedValue[] = round((float) ($receivedValueByDate[$key] ?? 0), 2);
            $cursor->addDay();
        }

        return [
            'labels' => $labels,
            'created' => $created,
            'shipped' => $shipped,
            'revenue' => $revenue,
            'shipped_value' => $shippedValue,
            'received_value' => $receivedValue,
        ];
    }

    /**
     * KPI: сьогодні + за період + дельта проти попереднього періоду.
     */
    private function buildKpis(Carbon $rangeStart, Carbon $rangeEnd, Carbon $today, string $tz, array $series): array
    {
        $todayStart = $today->copy()->startOfDay();
        $todayEnd = $today->copy()->endOfDay();

        $createdToday = Order::whereBetween('created_at', [$todayStart, $todayEnd])->count();
        $createdPeriod = array_sum($series['created']);

        $shippedPeriod = array_sum($series['shipped']);
        $shippedToday = (int) ($series['shipped'][count($series['shipped']) - 1] ?? 0);

        $revenueToday = (float) OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereBetween('orders.created_at', [$todayStart, $todayEnd])
            ->sum('order_items.total');
        $revenuePeriod = array_sum($series['revenue']);

        $shippedValuePeriod = array_sum($series['shipped_value']);
        $shippedValueToday = (float) ($series['shipped_value'][count($series['shipped_value']) - 1] ?? 0);
        $receivedValuePeriod = array_sum($series['received_value']);
        $receivedValueToday = (float) ($series['received_value'][count($series['received_value']) - 1] ?? 0);

        $avgCheck = $createdPeriod > 0 ? $revenuePeriod / $createdPeriod : 0;

        // Попередній період для дельти
        $days = count($series['labels']);
        $prevStart = $rangeStart->copy()->subDays($days);
        $prevEnd = $rangeStart->copy()->subSecond();

        $prevRevenue = (float) OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereBetween('orders.created_at', [$prevStart, $prevEnd])
            ->sum('order_items.total');
        $prevCreated = Order::whereBetween('created_at', [$prevStart, $prevEnd])->count();

        return [
            'created' => [
                'today' => $createdToday,
                'period' => $createdPeriod,
                'delta' => $this->delta($createdPeriod, $prevCreated),
            ],
            'shipped' => [
                'today' => $shippedToday,
                'period' => $shippedPeriod,
            ],
            'revenue' => [
                'today' => round($revenueToday, 2),
                'period' => round($revenuePeriod, 2),
                'delta' => $this->delta($revenuePeriod, $prevRevenue),
            ],
            'shipped_value' => [
                'today' => round($shippedValueToday, 2),
                'period' => round($shippedValuePeriod, 2),
            ],
            'received_value' => [
                'today' => round($receivedValueToday, 2),
                'period' => round($receivedValuePeriod, 2),
            ],
            'avg_check' => round($avgCheck, 2),
        ];
    }

    private function delta(float $current, float $previous): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * Останні замовлення для таблиці.
     */
    private function recentOrders(): array
    {
        return Order::query()
            ->with(['customer:id,first_name,last_name', 'statusRef:id,code,name,color'])
            ->withSum('items', 'total')
            ->latest('id')
            ->limit(8)
            ->get(['id', 'order_number', 'status', 'status_id', 'customer_id', 'created_at'])
            ->map(function (Order $order) {
                $name = trim(($order->customer->first_name ?? '') . ' ' . ($order->customer->last_name ?? ''));

                return [
                    'id' => $order->id,
                    'number' => $order->order_number ?: (string) $order->id,
                    'customer' => $name !== '' ? $name : 'Клієнт',
                    'status' => $order->statusRef->name ?? $order->status ?? '—',
                    'status_color' => $order->statusRef->color ?? null,
                    'total' => round((float) ($order->items_sum_total ?? 0), 2),
                    'created_at' => optional($order->created_at)->toIso8601String(),
                ];
            })
            ->all();
    }

    /**
     * Топ товарів за період (за виторгом).
     */
    private function topProducts(Carbon $rangeStart, Carbon $rangeEnd): array
    {
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereBetween('orders.created_at', [$rangeStart, $rangeEnd])
            ->selectRaw('order_items.product_title as title, SUM(order_items.qty) as qty, SUM(order_items.total) as total')
            ->groupBy('order_items.product_title')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'title' => $row->title ?: 'Товар',
                'qty' => (int) $row->qty,
                'total' => round((float) $row->total, 2),
            ])
            ->all();
    }

    /**
     * Розподіл замовлень за джерелом (звідки прийшли) за період.
     */
    private function sourceBreakdown(Carbon $rangeStart, Carbon $rangeEnd): array
    {
        return Order::query()
            ->leftJoin('order_sources', 'order_sources.id', '=', 'orders.source_id')
            ->whereBetween('orders.created_at', [$rangeStart, $rangeEnd])
            ->selectRaw('COALESCE(order_sources.name, orders.source, "Інше") as label, order_sources.code as code, order_sources.color as color, order_sources.icon as icon, COUNT(*) as c')
            ->groupBy('label', 'code', 'color', 'icon')
            ->orderByDesc('c')
            ->get()
            ->map(fn ($row) => [
                'label' => $row->label ?: 'Інше',
                'code' => $row->code,
                'color' => $row->color,
                'icon' => $row->icon,
                'count' => (int) $row->c,
            ])
            ->all();
    }
}
