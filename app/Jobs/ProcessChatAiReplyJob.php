<?php

namespace App\Jobs;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\ChatAiAssistantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessChatAiReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        private readonly int $conversationId,
        private readonly int $messageId
    ) {
    }

    public function handle(ChatAiAssistantService $chatAiAssistant): void
    {
        $conversation = ChatConversation::query()->find($this->conversationId);
        $message = ChatMessage::query()->with('attachments')->find($this->messageId);

        if (!$conversation || !$message) {
            return;
        }

        $chatAiAssistant->processInboundMessage($conversation, $message);
    }
}
