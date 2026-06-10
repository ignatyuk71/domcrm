<?php

namespace App\Services\Meta;

use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Імпорт існуючих розмов через Graph API Conversations.
 * Вебхук приносить лише нові повідомлення; цей сервіс підтягує історію.
 */
class MetaSyncService
{
    private function graph(): string
    {
        return 'https://graph.facebook.com/' . (config('services.meta.graph_version', 'v21.0'));
    }

    /** Імпортувати нещодавні розмови підключення (Messenger + Instagram). Повертає к-ть нових повідомлень. */
    public function syncConnection(MetaConnection $conn, int $convLimit = 25, int $msgLimit = 30): int
    {
        $count = $this->syncChannel($conn, 'messenger', $convLimit, $msgLimit);
        if ($conn->ig_account_id) {
            $count += $this->syncChannel($conn, 'instagram', $convLimit, $msgLimit);
        }

        return $count;
    }

    /**
     * Підтягнути історію ОДНОГО контакту (через ?user_id).
     * $clearFirst — спершу видалити наявні повідомлення розмови (тільки якщо Graph відповів успішно),
     * щоб історія перезаписалась з нуля в правильному порядку.
     * Повертає к-ть імпортованих повідомлень або false, якщо Graph не відповів.
     */
    public function syncContactConversation(MetaConnection $conn, InboxContact $contact, int $msgLimit = 100, bool $clearFirst = false): int|false
    {
        $platform = $contact->channel === 'instagram' ? 'instagram' : 'messenger';

        $r = Http::get($this->graph()."/{$conn->page_id}/conversations", [
            'platform' => $platform,
            'user_id' => $contact->external_id,
            'fields' => "participants,updated_time,messages.limit({$msgLimit}){id,message,from,created_time,attachments}",
            'access_token' => $conn->page_access_token,
        ]);

        if (!$r->successful()) {
            Log::warning('Meta contact sync failed', ['contact' => $contact->id, 'body' => mb_substr($r->body(), 0, 300)]);
            return false;
        }

        if ($clearFirst) {
            InboxConversation::where('inbox_contact_id', $contact->id)->first()?->messages()->delete();
        }

        $imported = 0;
        foreach ($r->json('data') ?? [] as $conv) {
            $imported += $this->importConversation($conn, $contact->channel, $conv);
        }

        return $imported;
    }

    private function syncChannel(MetaConnection $conn, string $platform, int $convLimit, int $msgLimit): int
    {
        $channel = $platform === 'instagram' ? 'instagram' : 'facebook';

        $r = Http::get($this->graph()."/{$conn->page_id}/conversations", [
            'platform' => $platform,
            'fields' => "participants,updated_time,messages.limit({$msgLimit}){id,message,from,created_time,attachments}",
            'limit' => $convLimit,
            'access_token' => $conn->page_access_token,
        ]);

        if (!$r->successful()) {
            Log::warning('Meta sync failed', ['page' => $conn->page_id, 'platform' => $platform, 'body' => $r->body()]);
            return 0;
        }

        $imported = 0;
        foreach ($r->json('data') ?? [] as $conv) {
            $imported += $this->importConversation($conn, $channel, $conv);
        }

        return $imported;
    }

    private function importConversation(MetaConnection $conn, string $channel, array $conv): int
    {
        $selfIds = array_filter([$conn->page_id, $conn->ig_account_id]);

        // Контакт = учасник, що не є нашою сторінкою/IG.
        $participant = null;
        foreach ($conv['participants']['data'] ?? [] as $p) {
            if (!empty($p['id']) && !in_array((string) $p['id'], array_map('strval', $selfIds), true)) {
                $participant = $p;
                break;
            }
        }
        if (!$participant) {
            return 0;
        }

        $name = $participant['name'] ?? $participant['username'] ?? null;

        $contact = InboxContact::firstOrCreate(
            ['meta_connection_id' => $conn->id, 'channel' => $channel, 'external_id' => (string) $participant['id']],
            ['name' => $name]
        );
        if (!$contact->name && $name) {
            $contact->update(['name' => $name]);
        }

        $conversation = InboxConversation::firstOrCreate(
            ['inbox_contact_id' => $contact->id],
            ['meta_connection_id' => $conn->id, 'channel' => $channel]
        );

        $msgs = $conv['messages']['data'] ?? [];           // найновіші перші
        $ordered = array_reverse($msgs);                   // зберігаємо від старих до нових
        $imported = 0;

        foreach ($ordered as $m) {
            $mid = $m['id'] ?? null;
            if (!$mid || InboxMessage::where('external_message_id', $mid)->exists()) {
                continue;
            }

            $fromId = (string) ($m['from']['id'] ?? '');
            $direction = in_array($fromId, array_map('strval', $selfIds), true) ? 'out' : 'in';

            $attachments = [];
            foreach ($m['attachments']['data'] ?? [] as $att) {
                $url = $att['image_data']['url'] ?? $att['file_url'] ?? $att['video_data']['url'] ?? null;
                if ($url) {
                    $attachments[] = ['type' => $att['mime_type'] ?? 'file', 'url' => $url];
                }
            }

            InboxMessage::create([
                'inbox_conversation_id' => $conversation->id,
                'direction' => $direction,
                'sender' => $direction === 'out' ? 'agent' : 'contact',
                'external_message_id' => $mid,
                'text' => $m['message'] ?? null,
                'attachments' => $attachments ?: null,
                'sent_at' => isset($m['created_time']) ? Carbon::parse($m['created_time'])->setTimezone(config('app.timezone')) : now(),
            ]);
            $imported++;
        }

        // Прев'ю діалогу з найновішого повідомлення.
        $last = $msgs[0] ?? null;
        if ($last) {
            $fromId = (string) ($last['from']['id'] ?? '');
            $dir = in_array($fromId, array_map('strval', $selfIds), true) ? 'out' : 'in';
            $conversation->update([
                'last_message_at' => isset($last['created_time']) ? Carbon::parse($last['created_time'])->setTimezone(config('app.timezone')) : now(),
                'last_message_text' => !empty($last['message']) ? mb_substr($last['message'], 0, 480) : '[вкладення]',
                'last_message_direction' => $dir,
            ]);
        }

        return $imported;
    }
}
