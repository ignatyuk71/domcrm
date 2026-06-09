<?php

namespace App\Services\Meta;

use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Розбирає вебхук-події Meta (Messenger + Instagram) і складає вхідні
 * повідомлення в inbox_* таблиці. Без відправки — лише прийом (Фаза 2, крок 1).
 */
class MetaWebhookProcessor
{
    /** Перевірка підпису X-Hub-Signature-256 (HMAC-SHA256 тіла на app_secret). */
    public function verifySignature(string $rawBody, ?string $signatureHeader): bool
    {
        $secret = (string) config('services.meta.app_secret');
        if ($secret === '' || !$signatureHeader) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signatureHeader);
    }

    /** Обробити payload. Повертає кількість збережених повідомлень. */
    public function process(array $payload): int
    {
        $channel = ($payload['object'] ?? null) === 'instagram' ? 'instagram' : 'facebook';
        $stored = 0;

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['messaging'] ?? [] as $event) {
                if ($this->handleEvent($entry, $event, $channel)) {
                    $stored++;
                }
            }
        }

        return $stored;
    }

    private function handleEvent(array $entry, array $event, string $channel): bool
    {
        $message = $event['message'] ?? null;

        // Поки беремо лише вхідні текст/медіа. Echo (наші ж), delivery, read — пропускаємо.
        if (!$message || !empty($message['is_echo'])) {
            return false;
        }

        $senderId = $event['sender']['id'] ?? null;
        $recipientId = $event['recipient']['id'] ?? null;
        if (!$senderId || !$recipientId) {
            return false;
        }

        // Знайти підключення: FB → page_id = recipient; IG → ig_account_id = recipient/entry id.
        $connection = $channel === 'instagram'
            ? MetaConnection::query()
                ->where('ig_account_id', $recipientId)
                ->orWhere('ig_account_id', $entry['id'] ?? '___')
                ->first()
            : MetaConnection::query()->where('page_id', $recipientId)->first();

        if (!$connection) {
            Log::warning('Inbox: підключення не знайдено', ['channel' => $channel, 'recipient' => $recipientId]);
            return false;
        }

        $mid = $message['mid'] ?? null;
        if ($mid && InboxMessage::where('external_message_id', $mid)->exists()) {
            return false; // дубль
        }

        $contact = InboxContact::firstOrCreate([
            'meta_connection_id' => $connection->id,
            'channel' => $channel,
            'external_id' => (string) $senderId,
        ]);

        $conversation = InboxConversation::firstOrCreate(
            ['inbox_contact_id' => $contact->id],
            ['meta_connection_id' => $connection->id, 'channel' => $channel]
        );

        $attachments = [];
        foreach ($message['attachments'] ?? [] as $att) {
            $attachments[] = [
                'type' => $att['type'] ?? 'file',
                'url' => $att['payload']['url'] ?? null,
            ];
        }

        $text = $message['text'] ?? null;
        $sentAt = isset($event['timestamp'])
            ? Carbon::createFromTimestampMs((int) $event['timestamp'])
            : now();

        InboxMessage::create([
            'inbox_conversation_id' => $conversation->id,
            'direction' => 'in',
            'sender' => 'contact',
            'external_message_id' => $mid,
            'text' => $text,
            'attachments' => $attachments ?: null,
            'sent_at' => $sentAt,
        ]);

        $conversation->update([
            'last_message_at' => $sentAt,
            'last_message_text' => $text ? mb_substr($text, 0, 480) : '[вкладення]',
            'last_message_direction' => 'in',
        ]);
        $conversation->increment('unread_count');

        return true;
    }
}
