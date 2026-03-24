<?php

namespace App\Observers;

use App\Models\ChatMessage;
use App\Services\ChatAiOrchestratorService;
use Illuminate\Support\Facades\Log;

class ChatMessageObserver
{
    public function created(ChatMessage $message): void
    {
        if ($message->direction !== 'inbound' || $message->source !== 'webhook') {
            return;
        }

        if (str_starts_with((string) $message->external_message_id, 'comment:')) {
            return;
        }

        $conversation = $message->conversation()
            ->with(['contact', 'customer'])
            ->first();

        if (!$conversation?->contact || !$conversation->customer) {
            return;
        }

        $platform = (string) $conversation->contact->platform;
        if (!in_array($platform, ['messenger', 'instagram'], true)) {
            return;
        }

        try {
            app(ChatAiOrchestratorService::class)->handleInboundMessage(
                $conversation,
                $message,
                $conversation->customer,
                $conversation->contact,
                $platform
            );
        } catch (\Throwable $e) {
            Log::error('Chat AI observer failed', [
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
