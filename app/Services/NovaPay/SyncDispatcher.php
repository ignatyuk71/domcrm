<?php

namespace App\Services\NovaPay;

use App\Jobs\SyncNovaPay;
use App\Models\NovaPayAccount;
use App\Models\NovaPayConnection;
use App\Models\NovaPaySyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class SyncDispatcher
{
    public function enqueue(NovaPayConnection $connection, string $source, ?NovaPayAccount $account = null, ?string $date = null): ?NovaPaySyncRun
    {
        $key = 'novapay:dispatch:'.$connection->id.':'.($account?->id ?? 0).':'.$source.':'.$date;
        return Cache::lock($key, 10)->get(function () use ($connection, $source, $account, $date) {
            $pending = NovaPaySyncRun::query()->where('connection_id', $connection->id)
                ->where('account_id', $account?->id)->where('source', $source)->where('date_from', $date)
                ->whereIn('status', ['queued', 'running', 'retrying'])->exists();
            if ($pending) {
                return null;
            }
            $run = NovaPaySyncRun::create([
                'connection_id' => $connection->id, 'account_id' => $account?->id,
                'source' => $source, 'date_from' => $date, 'date_to' => $date, 'status' => 'queued',
            ]);
            try {
                // NovaPay завжди виконується у фоні, навіть коли загальна черга CRM — sync.
                SyncNovaPay::dispatch($run->id)->onConnection('database')->onQueue('novapay')->afterCommit();
            } catch (\Throwable) {
                $run->update(['status' => 'failed', 'finished_at' => now(), 'error_code' => 'dispatch', 'error_message' => 'Не вдалося поставити оновлення у чергу. Повторіть дію.']);
                throw new NovaPayException('dispatch', 'Не вдалося поставити оновлення у чергу. Повторіть дію.');
            }

            return $run;
        }) ?: null;
    }

    public function refresh(NovaPayAccount $account, bool $reconcile = false): int
    {
        $connection = $account->connection;
        if (! $account->enabled || $connection->requires_auth) {
            return 0;
        }
        $count = $this->enqueue($connection, 'balance', $account) ? 1 : 0;
        if (! config('novapay.operations_verified')) {
            return $count;
        }
        $today = CarbonImmutable::now($account->timezone)->startOfDay();
        $from = $account->import_from?->toDateString() ?? $today->subDays(max(1, config('novapay.initial_days')) - 1)->toDateString();
        // Добираємо прогалини навіть після збоїв довших за вікно щоденної звірки.
        $completed = NovaPaySyncRun::query()->where('account_id', $account->id)->where('source', 'operations')
            ->where('status', 'success')->pluck('date_from')->map(fn ($date) => $date->toDateString())->all();
        $reconcileFrom = $today->subDays(max(1, config('novapay.reconcile_days')) - 1)->toDateString();
        for ($day = CarbonImmutable::parse($from, $account->timezone); $day <= $today; $day = $day->addDay()) {
            $date = $day->toDateString();
            if (! in_array($date, $completed, true) || $date === $today->toDateString() || ($reconcile && $date >= $reconcileFrom)) {
                $count += $this->enqueue($connection, 'operations', $account, $date) ? 1 : 0;
            }
        }

        return $count;
    }
}
