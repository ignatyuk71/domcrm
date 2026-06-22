<?php

namespace App\Services\Ai;

use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Services\Meta\MetaSendService;

/**
 * Відправлення вихідних повідомлень бота та ідемпотентний запис їх в історію.
 * Винесено з AiAgentService.
 */
class OutgoingMessageWriter
{
    public function __construct(private MetaSendService $send)
    {
    }

    /** Надіслати клієнту текст від імені бота + зберегти його в історії розмови. */
    public function sendBotMessage(InboxConversation $conversation, string $text): bool
    {
        $conversation->loadMissing(['connection', 'contact']);
        if (!$conversation->connection || !$conversation->contact) {
            return false;
        }

        $sent = $this->send->sendText($conversation->connection, $conversation->contact->external_id, $text);
        if (!($sent['ok'] ?? false)) {
            return false;
        }

        $this->persistOutgoing([
            'inbox_conversation_id' => $conversation->id,
            'direction' => 'out',
            'sender' => 'ai',
            'external_message_id' => $sent['message_id'] ?? null,
            'text' => $text,
            'sent_at' => now(),
        ]);
        $conversation->update([
            'last_message_at' => now(),
            'last_message_text' => mb_substr($text, 0, 480),
            'last_message_direction' => 'out',
        ]);

        return true;
    }

    /**
     * Ідемпотентний запис вихідного повідомлення.
     *
     * Те саме повідомлення доходить двома шляхами — наш send-API і echo-вебхук
     * Meta. Без захисту вони ловлять гонку: unique-обмеження на
     * external_message_id кидає 1062 (дубль), а якщо echo встиг першим — пише
     * наш бот як 'agent' і вмикає хибну «паузу оператора». Тут: якщо запис із
     * таким mid уже існує — не дублюємо, лише виправляємо відправника на 'ai'.
     */
    public function persistOutgoing(array $attrs): void
    {
        $mid = $attrs['external_message_id'] ?? null;

        if ($mid !== null && $this->reconcileOutgoingEcho($mid)) {
            return;
        }

        try {
            InboxMessage::create($attrs);
        } catch (\Illuminate\Database\QueryException $e) {
            // Гонка: echo вставив той самий mid між перевіркою і create.
            if ($mid !== null && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                $this->reconcileOutgoingEcho($mid);

                return;
            }

            throw $e;
        }
    }

    /** Echo вже записав це повідомлення → виправити відправника 'agent'→'ai'. */
    private function reconcileOutgoingEcho(string $mid): bool
    {
        $existing = InboxMessage::where('external_message_id', $mid)->first();
        if (!$existing) {
            return false;
        }
        if ($existing->sender !== 'ai') {
            $existing->update(['sender' => 'ai']);
        }

        return true;
    }
}
