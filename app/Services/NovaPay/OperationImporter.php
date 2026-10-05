<?php

namespace App\Services\NovaPay;

use App\Models\NovaPayAccount;
use App\Models\NovaPayOperation;
use Illuminate\Support\Facades\DB;

class OperationImporter
{
    public function import(NovaPayAccount $account, array $records): void
    {
        DB::transaction(function () use ($account, $records) {
            foreach ($records as $record) {
                $fingerprint = hash('sha256', json_encode($record, JSON_THROW_ON_ERROR));
                $operation = NovaPayOperation::query()->where('account_id', $account->id)
                    ->where('provider_id', $record['provider_id'])->lockForUpdate()->first();
                if ($operation?->fingerprint === $fingerprint) {
                    continue;
                }
                if ($operation) {
                    DB::table('novapay_operation_revisions')->insert([
                        'operation_id' => $operation->id,
                        'snapshot' => json_encode($operation->only(['provider_id', 'booked_on', 'direction', 'amount_minor', 'status', 'source_status', 'counterparty', 'purpose']), JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                    ]);
                }
                ($operation ?? new NovaPayOperation)->forceFill([
                    ...$record, 'account_id' => $account->id, 'fingerprint' => $fingerprint,
                ])->save();
            }
        });
    }
}
