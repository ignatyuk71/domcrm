<?php

namespace App\Services\NovaPay;

use App\Models\NovaPayAccount;
use App\Models\NovaPayBalance;
use App\Models\NovaPayConnection;
use App\Models\NovaPaySyncRun;
use Illuminate\Support\Facades\DB;

class SyncService
{
    public function __construct(private NovaPayClient $client, private OperationImporter $importer) {}

    public function execute(NovaPaySyncRun $run): void
    {
        $connection = NovaPayConnection::findOrFail($run->connection_id);
        if ($run->source === 'discovery') {
            $accounts = $this->client->accounts($connection);
            DB::transaction(function () use ($connection, $accounts, $run) {
                foreach ($accounts as $data) {
                    $existing = $connection->accounts()->where('provider_id', $data['provider_id'])->first();
                    if ($existing && ($existing->iban !== $data['iban'] || $existing->currency !== $data['currency'])) {
                        throw new NovaPayException('identity_changed', 'Реквізити наявного рахунку змінилися. Потрібна перевірка підключення.');
                    }
                    $connection->accounts()->updateOrCreate(['provider_id' => $data['provider_id']], $data);
                }
                $connection->update(['verified_at' => now()]);
                $this->succeed($run, count($accounts));
            });

            return;
        }
        $account = NovaPayAccount::query()->where('connection_id', $connection->id)->findOrFail($run->account_id);
        if (! $account->enabled) {
            throw new NovaPayException('disabled', 'Оновлення рахунку призупинено.');
        }
        if ($run->source === 'balance') {
            $balances = $this->client->balance($account);
            DB::transaction(function () use ($account, $balances, $run) {
                foreach ($balances as $type => $amount) {
                    NovaPayBalance::updateOrCreate(['account_id' => $account->id, 'type' => $type], [
                        'amount_minor' => $amount, 'received_at' => now(),
                    ]);
                }
                $this->succeed($run, count($balances));
            });

            return;
        }
        if ($run->source === 'payouts') {
            if (! config('novapay.payouts_verified')) throw new NovaPayException('contract_pending', 'Імпорт реєстрів післяплати ще не увімкнено.');
            $from = $run->date_from->toDateString();
            $to = $run->date_to->toDateString();
            if (! $run->statement_id) {
                $run->update(['statement_id' => $this->client->startPayoutRegister($account, $from, $to)]);
                // Генерація асинхронна: звільняємо worker, наступна спроба читає той самий реєстр.
                throw new NovaPayException('register_pending', 'Реєстр післяплати замовлено. Очікуємо формування NovaPay.', true);
            }
            $records = app(PayoutNormalizer::class)->normalize($this->client->payoutRegister($account, $run->statement_id), $account, $from, $to);
            DB::transaction(function () use ($account, $records, $run, $from, $to) {
                app(PayoutImporter::class)->import($account, $records, $from, $to, $run->created_at->format('Y-m-d H:i:s'));
                $this->succeed($run, count($records));
            });

            return;
        }
        if ($run->source === 'operations') {
            $records = $this->client->operations($account, $run->date_from->toDateString(), $run->date_to->toDateString());
            DB::transaction(function () use ($account, $records, $run) {
                $this->importer->import($account, $records);
                $this->succeed($run, count($records));
            });

            return;
        }
        throw new NovaPayException('invalid_source', 'Непідтримуване джерело синхронізації.');
    }

    private function succeed(NovaPaySyncRun $run, int $count): void
    {
        $run->update([
            'status' => 'success', 'records_count' => $count, 'succeeded_at' => now(), 'finished_at' => now(),
            'error_code' => null, 'error_message' => null,
        ]);
    }
}
