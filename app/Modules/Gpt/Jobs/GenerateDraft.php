<?php

namespace App\Modules\Gpt\Jobs;

use App\Modules\Gpt\Application\DraftService;
use App\Modules\Gpt\Models\GptRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class GenerateDraft implements ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    public int $timeout = 75;

    public function __construct(public int $runId)
    {
        $this->onConnection(config('gpt-drafts.connection'));
        $this->onQueue(config('gpt-drafts.queue'));
    }

    public function handle(DraftService $service): void
    {
        $run = GptRun::find($this->runId);
        if (! $run || in_array($run->status, ['completed', 'failed'], true)) {
            return;
        }
        $lock = Cache::lock('gpt-drafts:dialogue:'.$run->gpt_dialogue_id, 85);
        if (! $lock->get()) {
            $this->release(5);

            return;
        }
        try {
            $service->generate($run->fresh());
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $error): void
    {
        GptRun::whereKey($this->runId)->whereIn('status', ['pending', 'running'])->update([
            'status' => 'failed', 'error_code' => 'worker_failed', 'finished_at' => now(),
            'error_message' => 'Обробку перервано. Повідомлення збережено; повторіть створення чернетки.',
        ]);
    }
}
