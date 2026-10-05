<?php

namespace App\Jobs;

use App\Models\NovaPaySyncRun;
use App\Services\NovaPay\NovaPayException;
use App\Services\NovaPay\SyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class SyncNovaPay implements ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    public int $maxExceptions = 4;

    public int $timeout = 75;

    public bool $failOnTimeout = true;

    public function __construct(public int $runId) {}

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function middleware(): array
    {
        $run = NovaPaySyncRun::find($this->runId);
        $scope = $run?->source === 'discovery' ? 'connection:'.$run->connection_id : 'account:'.$run?->account_id;

        return [(new WithoutOverlapping('novapay:'.$scope.':'.$run?->source))->releaseAfter(30)->expireAfter(85)];
    }

    public function handle(SyncService $service): void
    {
        $run = NovaPaySyncRun::findOrFail($this->runId);
        if (! in_array($run->status, ['queued', 'running', 'retrying'], true)) {
            return;
        }
        $run->update(['status' => 'running', 'started_at' => now()]);
        try {
            $service->execute($run);
        } catch (NovaPayException $e) {
            $run->update([
                'status' => $e->retryable ? 'retrying' : 'failed',
                'finished_at' => $e->retryable ? null : now(),
                'error_code' => $e->reason, 'error_message' => $e->getMessage(),
            ]);
            if ($e->retryable) {
                throw $e;
            }
        } catch (Throwable) {
            // Технічний виняток не потрапляє в логи з потенційно секретним SQL чи HTTP-тілом.
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error_code' => 'internal', 'error_message' => 'Внутрішній збій синхронізації. Повторіть оновлення.']);
        }
    }

    public function failed(?Throwable $exception): void
    {
        NovaPaySyncRun::query()->whereKey($this->runId)->whereIn('status', ['queued', 'running', 'retrying'])->update([
            'status' => 'failed', 'finished_at' => now(), 'error_code' => 'retries_exhausted',
            'error_message' => 'Синхронізацію не завершено після повторних спроб. Перевірте чергу та повторіть оновлення.',
        ]);
    }
}
