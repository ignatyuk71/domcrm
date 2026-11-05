<?php

namespace App\Services\Ai\Gpt;

use App\Models\InboxConversation;
use App\Models\InboxMessage;
use Illuminate\Support\Facades\Cache;

/** Очікує завершення серії повідомлень покупця перед запуском GPT. */
class MessageBuffer
{
    public const QUIET_SECONDS = 4;

    private const MAX_WAIT_SECONDS = 60;

    public function received(InboxMessage $message): void
    {
        // Окремий ключ повідомлення не дозволяє старішому вебхуку пересунути час нового.
        Cache::put($this->key($message), now()->format('U.u'), now()->addMinutes(10));
    }

    public function remaining(InboxMessage $message): float
    {
        $receivedAt = Cache::get($this->key($message));
        if (! is_numeric($receivedAt)) {
            // База зберігає секунди: запас у секунду гарантує повне очікування після отримання.
            $receivedAt = ($message->created_at?->getTimestamp() ?? now()->getTimestamp()) + 1;
        }

        return max(0.0, (float) $receivedAt + self::QUIET_SECONDS - (float) now()->format('U.u'));
    }

    public function waitForQuiet(InboxConversation $conversation, int $messageId): ?int
    {
        $deadline = (float) now()->format('U.u') + self::MAX_WAIT_SECONDS;

        while (true) {
            $conversation->refresh();
            if (! $conversation->ai_enabled) {
                return null;
            }

            $latest = $conversation->messages()->where('sender', '!=', 'ai')->latest('id')->first();
            if (! $latest || $latest->direction !== 'in') {
                return null;
            }

            $budget = $deadline - (float) now()->format('U.u');
            if ($budget <= 0) {
                return null;
            }

            $messageId = $latest->id;
            $remaining = $this->remaining($latest);
            if ($remaining <= 0) {
                return $messageId;
            }

            // Після паузи перечитуємо чат: покупець міг продовжити, а оператор — втрутитися.
            $this->pause(min($remaining, $budget));
        }
    }

    protected function pause(float $seconds): void
    {
        usleep((int) ceil(max(0.0, $seconds) * 1_000_000));
    }

    private function key(InboxMessage $message): string
    {
        return 'gpt-message-buffer-received-'.$message->getKey();
    }
}
