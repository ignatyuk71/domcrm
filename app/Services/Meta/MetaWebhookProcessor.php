<?php

namespace App\Services\Meta;

use App\Jobs\AiRespondToMessage;

use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Розбирає вебхук-події Meta (Messenger + Instagram) і складає вхідні
 * повідомлення в inbox_* таблиці. Без відправки — лише прийом.
 */
class MetaWebhookProcessor
{
    public function __construct(private MetaOAuthService $oauth)
    {
    }

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
        if (!$message) {
            return false;
        }

        // Echo = вихідне повідомлення сторінки (відповідь працівника прямо у ФБ/IG
        // або наш власний Send API — той відсіється дедупом по mid).
        $isEcho = !empty($message['is_echo']);

        $senderId = $event['sender']['id'] ?? null;
        $recipientId = $event['recipient']['id'] ?? null;
        if (!$senderId || !$recipientId) {
            return false;
        }

        // Для echo сторінка — відправник, клієнт — отримувач; для вхідних навпаки.
        $pageSideId = $isEcho ? $senderId : $recipientId;
        $userId = (string) ($isEcho ? $recipientId : $senderId);

        $connection = $channel === 'instagram'
            ? MetaConnection::query()
                ->where('ig_account_id', $pageSideId)
                ->orWhere('ig_account_id', $entry['id'] ?? '___')
                ->first()
            : MetaConnection::query()->where('page_id', $pageSideId)->first();

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
            'external_id' => $userId,
        ]);

        // Best-effort: підтягнути імʼя + аватар (при створенні або якщо імені ще немає).
        if ($contact->wasRecentlyCreated || !$contact->name) {
            $profile = $this->oauth->getUserProfile($connection->page_access_token, $userId, $channel);
            if ($profile) {
                $contact->update(array_filter([
                    'name' => $profile['name'] ?? null,
                    'profile_pic' => $profile['profile_pic'] ?? null,
                ]));
            }

            // Профіль-АПІ часто закритий (400) — тоді імʼя беремо з Conversations API.
            if (!$contact->name) {
                $name = $this->oauth->getNameFromConversations($connection->page_access_token, $connection->page_id, $userId, $channel);
                if ($name) {
                    $contact->update(['name' => $name]);
                }
            }
        }

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

        $context = $this->extractContext($message);

        $text = $message['text'] ?? null;
        // Таймстемп фб — в UTC; приводимо до зони застосунку, інакше стрічка сортується врозкид.
        $sentAt = isset($event['timestamp'])
            ? Carbon::createFromTimestampMs((int) $event['timestamp'])->setTimezone(config('app.timezone'))
            : now();

        $msg = InboxMessage::create([
            'inbox_conversation_id' => $conversation->id,
            'direction' => $isEcho ? 'out' : 'in',
            'sender' => $isEcho ? 'agent' : 'contact',
            'external_message_id' => $mid,
            'text' => $text,
            'attachments' => $attachments ?: null,
            'context' => $context,
            'sent_at' => $sentAt,
        ]);

        $conversation->update([
            'last_message_at' => $sentAt,
            'last_message_text' => $text ? mb_substr($text, 0, 480) : '[вкладення]',
            'last_message_direction' => $isEcho ? 'out' : 'in',
        ]);
        if (!$isEcho) {
            $conversation->increment('unread_count');

            // AI-агент: думає вже ПІСЛЯ того, як вебхук віддав фейсбуку 200.
            if ($conversation->ai_enabled) {
                AiRespondToMessage::dispatchAfterResponse($conversation->id, $msg->id);
            }
        }

        return true;
    }

    /**
     * Контекст повідомлення: на ЩО відповів клієнт.
     * reply_to.mid → цитата конкретного повідомлення; reply_to.story → відповідь
     * на сторіс; вкладення share/media_share/story_mention → пересланий пост.
     * Медіа качаємо собі ОДРАЗУ — посилання Meta живуть недовго.
     */
    private function extractContext(array $message): ?array
    {
        $replyTo = $message['reply_to'] ?? null;

        if (!empty($replyTo['mid'])) {
            return ['type' => 'reply', 'mid' => (string) $replyTo['mid']];
        }

        if (!empty($replyTo['story'])) {
            $url = $replyTo['story']['url'] ?? null;

            return array_filter([
                'type' => 'story',
                'url' => $url,
                'story_id' => $replyTo['story']['id'] ?? null,
                'local' => $url ? $this->downloadContextMedia($url) : null,
            ]);
        }

        foreach ($message['attachments'] ?? [] as $att) {
            $type = $att['type'] ?? '';
            if (!in_array($type, ['share', 'media_share', 'story_mention'], true)) {
                continue;
            }
            $url = $att['payload']['url'] ?? null;

            return array_filter([
                'type' => $type === 'story_mention' ? 'story' : 'share',
                'url' => $url,
                'local' => $url ? $this->downloadContextMedia($url) : null,
            ]);
        }

        return null;
    }

    /** Скачати картинку контексту до себе. Повертає відносний шлях або null. */
    private function downloadContextMedia(string $url): ?string
    {
        try {
            $r = Http::timeout(10)->get($url);
            if (!$r->successful()) {
                return null;
            }
            $mime = trim(explode(';', (string) $r->header('Content-Type'))[0]);
            if (!str_starts_with($mime, 'image/')) {
                return null; // відео/посилання не качаємо — лишиться віддалений url
            }
            $body = $r->body();
            if (strlen($body) > 8 * 1024 * 1024) {
                return null;
            }

            $ext = match ($mime) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                default => 'jpg',
            };
            $dir = public_path('inbox-context');
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $name = Str::random(24) . '.' . $ext;
            file_put_contents($dir . '/' . $name, $body);

            return 'inbox-context/' . $name;
        } catch (\Throwable $e) {
            Log::info('Inbox: не вдалося скачати медіа контексту', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
