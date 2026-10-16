<?php

namespace App\Services\NovaPay;

use App\Models\NovaPayAccount;
use App\Models\NovaPayOperation;
use App\Models\NovaPayPayout;
use Illuminate\Support\Facades\DB;

class PayoutImporter
{
    public function import(NovaPayAccount $account, array $records, string $from, string $to, ?string $reportedAt = null): void
    {
        $reportedAt ??= now()->format('Y-m-d H:i:s');
        DB::transaction(function () use ($account, $records, $from, $to, $reportedAt) {
            NovaPayAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $existing = NovaPayPayout::where('account_id', $account->id)->whereBetween('paid_on', [$from, $to])->get()->keyBy('provider_id');
            $seen = [];
            foreach ($records as $record) {
                $fingerprint = hash('sha256', json_encode($record, JSON_THROW_ON_ERROR));
                $payout = $existing->get($record['provider_id']) ?? NovaPayPayout::where('account_id', $account->id)->where('provider_id', $record['provider_id'])->first();
                if ($payout && $payout->reported_at > $reportedAt) continue;
                $from = $payout ? min($from, $payout->paid_on) : $from;
                $to = $payout ? max($to, $payout->paid_on) : $to;
                if ($payout && ($payout->fingerprint !== $fingerprint || ! $payout->active)) $this->revision($payout);
                if (! $payout || $payout->fingerprint !== $fingerprint || ! $payout->active) {
                    ($payout ?? new NovaPayPayout)->forceFill([...$record, 'account_id' => $account->id, 'active' => true, 'fingerprint' => $fingerprint, 'operation_id' => null, 'reported_at' => $reportedAt])->save();
                } elseif ($payout->reported_at < $reportedAt) {
                    $payout->update(['reported_at' => $reportedAt]);
                }
                $seen[$record['provider_id']] = true;
            }
            foreach ($existing as $payout) {
                if ($payout->reported_at <= $reportedAt && $payout->active && ! isset($seen[$payout->provider_id])) {
                    $this->revision($payout);
                    $payout->update(['active' => false, 'operation_id' => null, 'reported_at' => $reportedAt]);
                }
            }
            $this->reconcile($account, $from, $to);
        });
    }

    public function reconcile(NovaPayAccount $account, string $from, string $to): void
    {
        // Підтверджуємо лише однозначну пару: рахунок, дата, точна сума й відправник NovaPay.
        $payouts = NovaPayPayout::where('account_id', $account->id)->where('active', true)->whereBetween('paid_on', [$from, $to])->get();
        $groups = $payouts->groupBy(fn ($p) => $p->paid_on.'|'.$p->register_number);
        $keys = $groups->map(fn ($rows) => $rows->first()->paid_on.'|'.$rows->sum('net_minor'));
        $operations = NovaPayOperation::where('account_id', $account->id)->where('direction', 'in')->where('status', 'posted')
            ->where('sender_tax_code', '38324133')->whereBetween('booked_on', [$from, $to])->get()->groupBy(fn ($op) => $op->booked_on->toDateString().'|'.$op->amount_minor);
        $multiplicity = $keys->countBy();
        foreach ($groups as $key => $rows) {
            $pair = $keys[$key];
            $candidates = $operations->get($pair, collect());
            $operationId = $multiplicity[$pair] === 1 && $candidates->count() === 1 ? $candidates->first()->id : null;
            NovaPayPayout::whereIn('id', $rows->pluck('id'))->where(function ($q) use ($operationId) {
                if ($operationId === null) $q->whereNotNull('operation_id');
                else $q->whereNull('operation_id')->orWhere('operation_id', '!=', $operationId);
            })->update(['operation_id' => $operationId]);
        }
    }

    private function revision(NovaPayPayout $payout): void
    {
        DB::table('novapay_payout_revisions')->insert(['payout_id' => $payout->id,
            'snapshot' => json_encode($payout->only(['provider_id', 'register_number', 'ttn', 'paid_on', 'gross_minor', 'fee_minor', 'net_minor', 'active', 'operation_id']), JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
