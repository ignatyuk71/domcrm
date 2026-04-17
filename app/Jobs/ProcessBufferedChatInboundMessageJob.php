<?php

namespace App\Jobs;

use App\Services\ChatAiOrchestratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessBufferedChatInboundMessageJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Повтори тут небажані: ідемпотентність тримаємо на message_id.
     */
    public int $tries = 1;

    /**
     * Даємо вікно, щоб не запускати дублікати на той самий inbound.
     */
    public int $uniqueFor = 180;

    public function __construct(
        private readonly int $messageId
    ) {
    }

    public function uniqueId(): string
    {
        return 'chat_ai_inbound_message_' . $this->messageId;
    }

    public function handle(ChatAiOrchestratorService $chatAiOrchestratorService): void
    {
        $chatAiOrchestratorService->handleBufferedInboundMessageById($this->messageId);
    }
}
