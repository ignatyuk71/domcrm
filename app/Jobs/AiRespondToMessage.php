<?php

namespace App\Jobs;

use App\Models\InboxConversation;
use App\Services\Ai\AiAgentService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

/**
 * Виконується ПІСЛЯ відповіді вебхуку (dispatchAfterResponse) —
 * фейсбук одразу отримує 200, а Claude думає вже у фоні того ж процесу.
 * Воркер черги не потрібен (важливо для шаред-хостингу).
 */
class AiRespondToMessage
{
    use Dispatchable;

    public function __construct(
        public int $conversationId,
        public int $messageId,
    ) {
    }

    public function handle(AiAgentService $ai): void
    {
        $conversation = InboxConversation::find($this->conversationId);
        if (!$conversation) {
            return;
        }

        // Пауза, щоб клієнт встиг дописати думку кількома повідомленнями.
        // Якщо за цей час прийшло нове — ця джоба змовчить (staleness-перевірка
        // в сервісі), а відповість джоба останнього повідомлення.
        $wait = (int) (\App\Models\AiSetting::global()->debounce_seconds ?? 10);
        if ($wait > 0 && !app()->runningUnitTests()) {
            sleep(min($wait, 60));
        }

        try {
            $ai->respond($conversation, $this->messageId);
        } catch (\Throwable $e) {
            Log::error('AI job failed', ['conv' => $this->conversationId, 'error' => $e->getMessage()]);
        }
    }
}
