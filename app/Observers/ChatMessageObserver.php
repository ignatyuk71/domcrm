<?php

namespace App\Observers;

use App\Jobs\ProcessBufferedChatInboundMessageJob;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Support\Facades\Log;

class ChatMessageObserver
{
    public function created(ChatMessage $message): void
    {
        if ($message->direction !== 'inbound' || $message->source !== 'webhook') {
            return;
        }

        $conversation = $message->conversation()
            ->with(['contact', 'customer'])
            ->first();

        if (($conversation?->thread_kind ?? null) === ChatConversation::THREAD_KIND_COMMENT) {
            return;
        }

        if (!$conversation?->contact || !$conversation->customer) {
            return;
        }

        $platform = (string) $conversation->contact->platform;
        if (!in_array($platform, ['messenger', 'instagram'], true)) {
            return;
        }

        try {
            $messageId = (int) $message->id;

            ProcessBufferedChatInboundMessageJob::dispatch($messageId)
                ->onConnection('background');
        } catch (\Throwable $e) {
            Log::error('Chat AI observer failed', [
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
