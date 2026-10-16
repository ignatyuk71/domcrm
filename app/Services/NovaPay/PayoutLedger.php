<?php

namespace App\Services\NovaPay;

use App\Models\NovaPayAccount;
use App\Models\NovaPayPayout;
use App\Models\NovaPaySyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PayoutLedger
{
    public function data(NovaPayAccount $account, string $from, string $to, int $page = 1): array
    {
        $base = NovaPayPayout::where('account_id', $account->id)->where('active', true)->whereBetween('paid_on', [$from, $to]);
        // Тільки точна ТТН. Дублі замовлень із тією самою ТТН потребують розбору, а не вибору першого.
        $matches = DB::table('order_deliveries as d')->join('orders as o', 'o.id', '=', 'd.order_id')
            ->where('d.carrier', 'nova_poshta')->whereIn('d.ttn', (clone $base)->select('ttn'))
            ->select('d.ttn')->selectRaw('COUNT(DISTINCT d.order_id) as candidates, MIN(d.order_id) as matched_order_id')->groupBy('d.ttn');
        $query = DB::table('novapay_payouts as p')->leftJoinSub($matches, 'm', 'm.ttn', '=', 'p.ttn')
            ->leftJoin('orders as o', fn ($join) => $join->on('o.id', '=', 'm.matched_order_id')->where('m.candidates', 1))
            ->where('p.account_id', $account->id)->where('p.active', true)->whereBetween('p.paid_on', [$from, $to]);
        $summary = (clone $query)->selectRaw('COUNT(*) as rows_count, COUNT(DISTINCT CASE WHEN p.operation_id IS NOT NULL THEN o.id END) as paid_orders,
            SUM(CASE WHEN o.id IS NOT NULL AND p.operation_id IS NOT NULL THEN p.net_minor ELSE 0 END) as matched_net,
            SUM(CASE WHEN m.candidates > 1 THEN 1 ELSE 0 END) as ambiguous,
            SUM(CASE WHEN m.candidates IS NULL THEN 1 ELSE 0 END) as unmatched,
            SUM(CASE WHEN p.operation_id IS NULL THEN 1 ELSE 0 END) as unconfirmed')->first();
        $runs = NovaPaySyncRun::where('account_id', $account->id)->where('source', 'payouts')->where('status', 'success')
            ->where('date_from', '<=', $to)->where('date_to', '>=', $from)->get();
        $complete = true;
        for ($day = CarbonImmutable::parse($from); $day->toDateString() <= $to; $day = $day->addDay()) {
            if (! $runs->contains(fn ($run) => $run->date_from->toDateString() <= $day->toDateString() && $run->date_to->toDateString() >= $day->toDateString())) {
                $complete = false;
                break;
            }
        }
        $rows = $query->select('p.id', 'p.ttn', 'p.paid_on', 'p.register_number', 'p.gross_minor', 'p.fee_minor', 'p.net_minor', 'p.operation_id', 'o.id as order_id', 'o.order_number', 'm.candidates')
            ->orderByDesc('p.paid_on')->orderByDesc('p.id')->paginate(25, ['*'], 'payout_page', $page)->through(fn ($row) => [
                'id' => $row->id, 'ttn' => $row->ttn, 'paid_on' => $row->paid_on, 'register_number' => $row->register_number,
                'gross_minor' => (string) $row->gross_minor, 'fee_minor' => (string) $row->fee_minor, 'net_minor' => (string) $row->net_minor,
                'order_id' => $row->order_id, 'order_number' => $row->order_number,
                'order_url' => $row->order_id ? route('orders.edit', ['order' => $row->order_id], false) : null,
                'match_status' => $row->candidates > 1 ? 'ambiguous' : ($row->order_id ? 'matched' : 'unmatched'),
                'confirmed' => $row->operation_id !== null,
            ]);

        return ['coverage_complete' => $complete, 'paid_orders_count' => $complete ? (int) $summary->paid_orders : null,
            'matched_net_minor' => (string) ($summary->matched_net ?? 0), 'unmatched' => (int) $summary->unmatched,
            'ambiguous' => (int) $summary->ambiguous, 'unconfirmed' => (int) $summary->unconfirmed,
            'updated_at' => $runs->max('succeeded_at')?->toIso8601String(), 'rows' => $rows];
    }
}
