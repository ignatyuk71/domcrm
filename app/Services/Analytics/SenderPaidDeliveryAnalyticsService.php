<?php

namespace App\Services\Analytics;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SenderPaidDeliveryAnalyticsService
{
    public function report(array $filters): array
    {
        $query = $this->shipments($filters);
        $summary = (clone $query)->selectRaw('COUNT(*) as shipments, SUM(is_sent) as sent,
            SUM(CASE WHEN cost IS NULL THEN 1 ELSE 0 END) as missing_prices,
            SUM(CASE WHEN is_sent = 1 AND cost IS NOT NULL THEN 1 ELSE 0 END) as priced,
            SUM(CASE WHEN is_sent = 1 THEN COALESCE(cost, 0) ELSE 0 END) as total_cost,
            SUM(CASE WHEN is_sent = 1 AND is_returned = 1 THEN 1 ELSE 0 END) as returned,
            SUM(CASE WHEN payer_verified = 0 THEN 1 ELSE 0 END) as unverified_payer,
            SUM(CASE WHEN date_verified = 0 THEN 1 ELSE 0 END) as fallback_dates')->first();
        $total = (int) $summary->shipments;
        $sent = (int) $summary->sent;
        $priced = (int) $summary->priced;
        $perPage = 10;
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($filters['sender_delivery_page'] ?? 1, $lastPage);
        $rows = (clone $query)->orderByDesc('document_date')->orderByDesc('delivery_id')
            ->offset(($page - 1) * $perPage)->limit($perPage)->get()->map(fn ($row) => [
                'order_id' => (int) $row->order_id,
                'order_number' => $row->order_number,
                'order_url' => route('orders.edit', ['order' => $row->order_id], false),
                'ttn' => $row->ttn,
                'document_date' => $row->document_date,
                'date_verified' => (bool) $row->date_verified,
                'status' => $row->status_name,
                'is_sent' => (bool) $row->is_sent,
                'is_returned' => (bool) $row->is_returned,
                'payer_verified' => (bool) $row->payer_verified,
                'cost' => $row->cost === null ? null : (float) $row->cost,
                'checked_at' => $row->checked_at,
            ])->all();

        return [
            'currency' => 'UAH', 'cost_source' => 'nova_poshta.DocumentCost',
            'totals' => [
                'shipments' => $total, 'sent' => $sent, 'priced' => $priced,
                'unknown_cost' => $sent - $priced,
                'not_sent' => $total - $sent,
                // Відсутність відповіді НП не перетворюється на безкоштовну доставку.
                'known_cost' => ($sent > 0 && $priced === 0) || ($total > 0 && (int) $summary->missing_prices === $total)
                    ? null : round((float) $summary->total_cost, 2),
                'returned' => (int) $summary->returned,
            ],
            'quality' => ['unverified_payer' => (int) $summary->unverified_payer, 'fallback_dates' => (int) $summary->fallback_dates, 'missing_prices' => (int) $summary->missing_prices],
            'rows' => ['data' => $rows, 'total' => $total, 'current_page' => $page, 'last_page' => $lastPage, 'per_page' => $perPage],
        ];
    }

    private function shipments(array $filters): Builder
    {
        $current = '(d.np_cost_ttn = TRIM(d.ttn) AND d.np_cost_checked_at IS NOT NULL)';
        $payer = "CASE WHEN {$current} THEN COALESCE(d.np_payer_type, d.delivery_payer) ELSE d.delivery_payer END";
        $date = "COALESCE(CASE WHEN {$current} THEN d.np_document_date END, d.created_at)";
        $base = DB::table('order_deliveries as d')->join('orders as o', 'o.id', '=', 'd.order_id')
            ->leftJoin('statuses as s', 's.id', '=', 'o.status_id')
            ->where('d.carrier', 'nova_poshta')->whereNotNull('d.ttn')->whereRaw("TRIM(d.ttn) <> ''")
            ->whereRaw("({$payer}) = ?", ['sender'])
            ->where('o.currency', $filters['currency'])
            ->whereBetween(DB::raw($date), [Carbon::parse($filters['date_from'])->startOfDay(), Carbon::parse($filters['date_to'])->endOfDay()->min(now())])
            ->when($filters['sale_type'], fn ($q, $v) => $q->where('o.sale_type', $v))
            ->when($filters['source_id'], fn ($q, $v) => $q->where('o.source_id', $v))
            ->when($filters['manager_id'], fn ($q, $v) => $q->where('o.manager_id', $v))
            ->select('d.id as delivery_id', 'o.id as order_id', 'o.order_number')
            ->selectRaw("TRIM(d.ttn) as ttn, {$date} as document_date, COALESCE(s.name, o.status) as status_name")
            ->selectRaw("CASE WHEN {$current} AND d.np_payer_type = 'sender' THEN d.np_document_cost END as cost")
            ->selectRaw("CASE WHEN {$current} THEN d.np_cost_checked_at END as checked_at")
            ->selectRaw("CASE WHEN {$current} AND d.np_payer_type = 'sender' THEN 1 ELSE 0 END as payer_verified")
            ->selectRaw("CASE WHEN {$current} AND d.np_document_date IS NOT NULL THEN 1 ELSE 0 END as date_verified")
            ->selectRaw("CASE WHEN {$current} AND d.np_cost_status_code IN ('4','5','6','7','8','9','10','11','41','102','103','108') THEN 1 ELSE 0 END as is_sent")
            ->selectRaw("CASE WHEN COALESCE(s.code, o.status) = 'returned' OR ({$current} AND d.np_cost_status_code IN ('102','103','108')) THEN 1 ELSE 0 END as is_returned")
            // Дублі замовлень не подвоюють доставку; беремо найсвіжішу відповідь по ТТН.
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY d.carrier, TRIM(d.ttn) ORDER BY d.np_cost_checked_at DESC, d.id DESC) as shipment_row');

        return DB::query()->fromSub($base, 'shipments')->where('shipment_row', 1);
    }
}
