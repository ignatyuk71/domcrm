<?php

namespace App\Jobs;

use App\Services\ChatAiAssistantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessChatAiReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $backoff = 10;

    public function __construct(
        private readonly int $messageId
    ) {
    }

    public function handle(ChatAiAssistantService $assistantService): void
    {
        $lock = Cache::lock("chat-ai:message:{$this->messageId}", 45);

        if (!$lock->get()) {
            return;
        }

        try {
            $result = $assistantService->processInboundMessage($this->messageId);

            Log::info('AI: обробка inbound повідомлення завершена', [
                'message_id' => $this->messageId,
                'result' => $result,
            ]);
        } finally {
            $lock->release();
        }
    }
}
