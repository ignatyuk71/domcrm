<?php

namespace App\Services\Meta;

use App\Models\MetaConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Відправка повідомлень через Meta Send API (Messenger + Instagram).
 * IG-листування йде тим самим page-токеном через /{page_id}/messages.
 */
class MetaSendService
{
    private function graph(): string
    {
        return 'https://graph.facebook.com/' . (config('services.meta.graph_version', 'v21.0'));
    }

    /**
     * Надіслати текст контакту.
     * @return array{ok: bool, message_id?: string|null, error?: string}
     */
    public function sendText(MetaConnection $conn, string $recipientId, string $text): array
    {
        $r = Http::asJson()->post($this->graph() . "/{$conn->page_id}/messages", [
            'recipient' => ['id' => $recipientId],
            'messaging_type' => 'RESPONSE',
            'message' => ['text' => $text],
            'access_token' => $conn->page_access_token,
        ]);

        if (!$r->successful()) {
            Log::warning('Meta send failed', ['page' => $conn->page_id, 'body' => $r->body()]);
            return ['ok' => false, 'error' => $r->json('error.message') ?? 'Не вдалося надіслати'];
        }

        return ['ok' => true, 'message_id' => $r->json('message_id')];
    }

    /**
     * Надіслати вкладення (image|file|video|audio) за публічним URL.
     * @return array{ok: bool, message_id?: string|null, error?: string}
     */
    public function sendAttachment(MetaConnection $conn, string $recipientId, string $type, string $url): array
    {
        $r = Http::asJson()->post($this->graph() . "/{$conn->page_id}/messages", [
            'recipient' => ['id' => $recipientId],
            'messaging_type' => 'RESPONSE',
            'message' => ['attachment' => ['type' => $type, 'payload' => ['url' => $url, 'is_reusable' => true]]],
            'access_token' => $conn->page_access_token,
        ]);

        if (!$r->successful()) {
            Log::warning('Meta send attachment failed', ['page' => $conn->page_id, 'body' => $r->body()]);
            return ['ok' => false, 'error' => $r->json('error.message') ?? 'Не вдалося надіслати файл'];
        }

        return ['ok' => true, 'message_id' => $r->json('message_id')];
    }
}
