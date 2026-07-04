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

        $this->trackAuthResult($conn, $r);

        if (!$r->successful()) {
            Log::warning('Meta send failed', ['page' => $conn->page_id, 'body' => $r->body()]);
            return ['ok' => false, 'error' => $r->json('error.message') ?? 'Не вдалося надіслати'];
        }

        return ['ok' => true, 'message_id' => $r->json('message_id')];
    }

    /**
     * Приватна відповідь на коментар: повідомлення летить людині в Messenger/Direct.
     * Обмеження Meta: одна відповідь на коментар, вікно 7 днів.
     * @return array{ok: bool, message_id?: string|null, error?: string}
     */
    public function sendPrivateReply(MetaConnection $conn, string $commentId, string $text): array
    {
        $r = Http::asJson()->post($this->graph() . "/{$conn->page_id}/messages", [
            'recipient' => ['comment_id' => $commentId],
            'message' => ['text' => $text],
            'access_token' => $conn->page_access_token,
        ]);

        $this->trackAuthResult($conn, $r);

        if (!$r->successful()) {
            Log::warning('Meta private reply failed', ['page' => $conn->page_id, 'comment' => $commentId, 'body' => $r->body()]);
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

        $this->trackAuthResult($conn, $r);

        if (!$r->successful()) {
            Log::warning('Meta send attachment failed', ['page' => $conn->page_id, 'body' => $r->body()]);
            return ['ok' => false, 'error' => $r->json('error.message') ?? 'Не вдалося надіслати файл'];
        }

        return ['ok' => true, 'message_id' => $r->json('message_id')];
    }

    /**
     * Живий трекінг здоровʼя підключення прямо з відправок:
     * мертвий токен (190/102) → status=error і банер у чаті одразу,
     * успішна відправка → підключення самовідновлюється в active.
     */
    private function trackAuthResult(MetaConnection $conn, \Illuminate\Http\Client\Response $r): void
    {
        if ($r->successful()) {
            if ($conn->status !== 'active') {
                $conn->update(['status' => 'active', 'last_error' => null]);
            }
            return;
        }

        if (in_array((int) $r->json('error.code'), [190, 102], true)) {
            $conn->update([
                'status' => 'error',
                'last_error' => 'Send API: ' . ($r->json('error.message') ?: 'токен недійсний')
                    . '. Перепідключіть сторінку в Налаштування → Meta.',
            ]);
        }
    }
}
