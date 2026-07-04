<?php

namespace App\Services\Meta;

use App\Jobs\AiRespondToMessage;

use App\Models\InboxComment;
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
    public function __construct(
        private MetaOAuthService $oauth,
        private InboxMediaStore $media,
    ) {
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

            // Коментарі під постами приходять окремим каналом — entry.changes.
            foreach ($entry['changes'] ?? [] as $change) {
                if ($this->handleCommentChange($entry, $change, $channel)) {
                    $stored++;
                }
            }
        }

        return $stored;
    }

    /**
     * Подія «прочитано»: клієнт відкрив чат. Ставимо watermark (last_read_at)
     * на розмову — усі вихідні з sent_at <= цього часу вважаємо переглянутими.
     */
    private function handleReadEvent(array $entry, array $event, string $channel): void
    {
        $userId = (string) ($event['sender']['id'] ?? '');     // хто прочитав = клієнт
        $pageSideId = $event['recipient']['id'] ?? null;        // наша сторінка/IG
        if ($userId === '' || !$pageSideId) {
            return;
        }

        $connection = $channel === 'instagram'
            ? MetaConnection::query()->where('ig_account_id', $pageSideId)->orWhere('ig_account_id', $entry['id'] ?? '___')->first()
            : MetaConnection::query()->where('page_id', $pageSideId)->first();
        if (!$connection) {
            return;
        }

        $contact = InboxContact::where('meta_connection_id', $connection->id)
            ->where('channel', $channel)->where('external_id', $userId)->first();
        $conversation = $contact ? InboxConversation::where('inbox_contact_id', $contact->id)->first() : null;
        if (!$conversation) {
            return;
        }

        $wmMs = $event['read']['watermark'] ?? ($event['timestamp'] ?? null);
        $readAt = $wmMs
            ? Carbon::createFromTimestampMs((int) $wmMs)->setTimezone(config('app.timezone'))
            : now();

        // Watermark лише вперед.
        if (!$conversation->last_read_at || $readAt->gt($conversation->last_read_at)) {
            $conversation->update(['last_read_at' => $readAt]);
        }
    }

    /** Коментар з вебхука (FB field=feed item=comment / IG field=comments). */
    private function handleCommentChange(array $entry, array $change, string $channel): bool
    {
        $field = $change['field'] ?? '';
        $v = $change['value'] ?? [];

        if ($channel === 'facebook') {
            // Видалив коментар у ФБ → прибираємо і в себе.
            if ($field === 'feed' && ($v['item'] ?? '') === 'comment' && ($v['verb'] ?? '') === 'remove') {
                InboxComment::where('comment_id', $v['comment_id'] ?? '___')->delete();
                return false;
            }
            if ($field !== 'feed' || ($v['item'] ?? '') !== 'comment' || ($v['verb'] ?? 'add') !== 'add') {
                return false;
            }
            $commentId = $v['comment_id'] ?? null;
            $postId = $v['post_id'] ?? null;
            $fromId = $v['from']['id'] ?? null;
            $fromName = $v['from']['name'] ?? null;
            $text = $v['message'] ?? null;
            $parent = ($v['parent_id'] ?? null) !== $postId ? ($v['parent_id'] ?? null) : null;
            $at = isset($v['created_time']) ? Carbon::createFromTimestamp((int) $v['created_time'])->setTimezone(config('app.timezone')) : now();
            $connection = MetaConnection::where('page_id', $entry['id'] ?? '___')->first();
            $selfId = $connection?->page_id;
        } else {
            if ($field !== 'comments') {
                return false;
            }
            $commentId = $v['id'] ?? null;
            $postId = $v['media']['id'] ?? null;
            $fromId = $v['from']['id'] ?? null;
            $fromName = $v['from']['username'] ?? null;
            $text = $v['text'] ?? null;
            $parent = $v['parent_id'] ?? null;
            $at = now();
            $connection = MetaConnection::where('ig_account_id', $entry['id'] ?? '___')->first();
            $selfId = $connection?->ig_account_id;
        }

        if (!$connection || !$commentId || !$postId || !$fromId) {
            return false;
        }
        // Власні відповіді сторінки під постом — не клієнтські коментарі.
        if ((string) $fromId === (string) $selfId) {
            return false;
        }
        if (InboxComment::where('comment_id', $commentId)->exists()) {
            return false; // дубль
        }

        [$postExcerpt, $postImage] = $this->postPreview($connection, $postId, $channel);

        $comment = InboxComment::create([
            'meta_connection_id' => $connection->id,
            'channel' => $channel,
            'post_id' => $postId,
            'post_excerpt' => $postExcerpt,
            'post_image' => $postImage,
            'comment_id' => $commentId,
            'parent_comment_id' => $parent,
            'from_id' => (string) $fromId,
            'from_name' => $fromName,
            'text' => $text,
            'commented_at' => $at,
        ]);

        // Автоворонка: ШІ відповідає в директ ПІСЛЯ того, як вебхук віддав 200.
        \App\Jobs\AiReplyToComment::dispatchAfterResponse($comment->id);

        return true;
    }

    /**
     * Превʼю поста (текст + картинка) для картки коментаря. Беремо з уже
     * збереженого коментаря цього ж поста або тягнемо з Graph і качаємо копію.
     * @return array{0: ?string, 1: ?string}
     */
    private function postPreview(MetaConnection $connection, string $postId, string $channel): array
    {
        $known = InboxComment::where('post_id', $postId)->whereNotNull('post_image')->first()
            ?? InboxComment::where('post_id', $postId)->first();
        if ($known) {
            return [$known->post_excerpt, $known->post_image];
        }

        try {
            $fields = $channel === 'instagram' ? 'media_url,thumbnail_url,caption' : 'full_picture,message';
            $r = Http::timeout(10)->get(
                'https://graph.facebook.com/' . config('services.meta.graph_version', 'v21.0') . "/{$postId}",
                ['fields' => $fields, 'access_token' => $connection->page_access_token]
            );
            if (!$r->successful()) {
                return [null, null];
            }
            $excerpt = mb_substr((string) ($r->json('message') ?? $r->json('caption') ?? ''), 0, 200) ?: null;
            $imgUrl = $r->json('full_picture') ?? $r->json('thumbnail_url') ?? $r->json('media_url');

            return [$excerpt, $imgUrl ? $this->downloadContextMedia($imgUrl) : null];
        } catch (\Throwable $e) {
            Log::info('Inbox: не вдалося отримати превʼю поста', ['post' => $postId, 'error' => $e->getMessage()]);
            return [null, null];
        }
    }

    private function handleEvent(array $entry, array $event, string $channel): bool
    {
        // Подія «прочитано» (FB message_reads / IG seen): клієнт відкрив чат.
        if (isset($event['read'])) {
            $this->handleReadEvent($entry, $event, $channel);

            return false; // це не збережене повідомлення
        }

        $message = $event['message'] ?? null;
        if (!$message) {
            // postback/optin/referral поки не обробляємо; лишаємо слід, щоб не губились
            // мовчки (deliveries не логуємо — то шум на кожну доставку).
            $skipped = collect(['postback', 'optin', 'referral'])->first(fn ($k) => isset($event[$k]));
            if ($skipped) {
                Log::info('Meta webhook: подія без обробника', ['type' => $skipped, 'channel' => $channel]);
            }

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

        // Best-effort: підтягнути імʼя + аватар. З фото — оновлюємо раз на 7 днів;
        // БЕЗ фото — щодня: IG спершу може відповісти «(#230) consent required»
        // і відкрити профіль уже за годину спілкування (кейс vunnutska_ 04.07).
        // checked_at тротлить спроби, щоб закрите профіль-АПІ не смикалось щоразу.
        $retryAfterDays = $contact->profile_pic ? 7 : 1;
        $picStale = !$contact->profile_pic_checked_at
            || $contact->profile_pic_checked_at->lt(now()->subDays($retryAfterDays));
        if ($contact->wasRecentlyCreated || !$contact->name || $picStale) {
            $profile = $this->oauth->getUserProfile($connection->page_access_token, $userId, $channel);

            // Аватарку одразу забираємо до себе: з CDN Meta браузери клієнтів
            // її часто не бачать (блокувальники, протухлий підпис лінка).
            $pic = $profile['profile_pic'] ?? null;
            if ($pic) {
                $pic = $this->media->downloadAvatar($pic, $contact->id) ?? $pic;
            }

            $contact->update(array_filter([
                'name' => $profile['name'] ?? null,
                'profile_pic' => $pic,
            ]) + ['profile_pic_checked_at' => now()]);

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

        // Ехо нашої приватної відповіді на коментар — це ШІ/система, не оператор.
        $isOurPrivateReply = $isEcho && $mid && InboxComment::where('dm_message_id', $mid)->exists();

        $text = $message['text'] ?? null;
        // Таймстемп фб — в UTC; приводимо до зони застосунку, інакше стрічка сортується врозкид.
        $sentAt = isset($event['timestamp'])
            ? Carbon::createFromTimestampMs((int) $event['timestamp'])->setTimezone(config('app.timezone'))
            : now();

        $msg = InboxMessage::create([
            'inbox_conversation_id' => $conversation->id,
            'direction' => $isEcho ? 'out' : 'in',
            'sender' => $isEcho ? ($isOurPrivateReply ? 'ai' : 'agent') : 'contact',
            'external_message_id' => $mid,
            'text' => $text,
            'attachments' => $attachments ?: null,
            'context' => $context,
            'sent_at' => $sentAt,
        ]);

        // Посилання Meta тимчасові → докачуємо медіа собі вже після відповіді вебхуку.
        if ($attachments) {
            \App\Jobs\PersistInboxAttachments::dispatchAfterResponse($msg->id);
        }

        $conversation->update([
            'last_message_at' => $sentAt,
            'last_message_text' => $text ? mb_substr($text, 0, 480) : '[вкладення]',
            'last_message_direction' => $isEcho ? 'out' : 'in',
        ]);

        // Оператор відповів прямо у ФБ/IG (не через CRM і не наш бот) → липка пауза ШІ
        // + прибираємо прапор «потрібен IBAN» (працівник уже в розмові).
        if ($isEcho && !$isOurPrivateReply) {
            $data = ['ai_order_needs_iban' => false];
            $minutes = (int) (\App\Models\AiSetting::global()->operator_pause_minutes ?? 3);
            if ($minutes > 0) {
                $data['ai_paused_until'] = now()->addMinutes($minutes);
            }
            $conversation->update($data);
        }

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
