<?php

namespace App\Services;

use App\Models\FacebookMessage; // Змінено
use App\Models\Conversation;
use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaService
{
    /**
     * Відправка повідомлення (текст або вкладення)
     */
    public function sendMessage(
        Customer $customer,
        string $text,
        array $attachments = [],
        string $platform = 'messenger'
    ): ?array // Повертаємо null при помилці, array при успіху
    {
        $settings = $this->getSettings();
        
        // Вибираємо правильний ID
        $recipientId = $platform === 'instagram' 
            ? $customer->instagram_user_id 
            : $customer->fb_user_id;

        // Fallback: якщо не знайшли по платформі, спробуємо хоч якийсь
        if (!$recipientId) {
            $recipientId = $customer->instagram_user_id ?: $customer->fb_user_id;
        }

        if (!$recipientId) {
            Log::error("Спроба відправки повідомлення клієнту {$customer->id} без ID соцмережі");
            return null;
        }

        $validAttachments = array_values(array_filter($attachments, function ($attachment) {
            return !empty($attachment['url']) && str_starts_with($attachment['url'], 'http');
        }));

        $payload = [
            'recipient' => ['id' => $recipientId],
            'messaging_type' => 'RESPONSE',
        ];

        if (!empty($validAttachments)) {
            $attachment = $validAttachments[0];
            $payload['message'] = [
                'attachment' => [
                    'type' => $attachment['type'] ?? 'image',
                    'payload' => [
                        'url' => $attachment['url'] ?? '',
                        'is_reusable' => true,
                    ],
                ],
            ];
        } else {
            // Meta API не дозволяє пусті повідомлення
            if (empty($text)) {
                return null;
            }
            $payload['message'] = ['text' => $text];
        }

        $response = Http::withToken($settings->access_token)
            ->post($this->graphUrl('/me/messages'), $payload);

        if ($response->failed()) {
            Log::error('Meta API Send Error', $response->json());
            return null;
        }

        return $response->json();
    }

    /**
     * Стягування історії при відкритті чату
     */
    public function syncHistory(Customer $customer): int
    {
        $settings = $this->getSettings();
        $recipientId = $customer->instagram_user_id ?: $customer->fb_user_id;
        $platform = $customer->instagram_user_id ? 'instagram' : 'messenger';

        if (!$recipientId) {
            return 0;
        }

        $threadId = $this->findThreadId($settings->access_token, $recipientId, $platform);
        if (!$threadId) {
            return 0;
        }

        $messagesUrl = $this->graphUrl("/{$threadId}/messages");
        $response = Http::withToken($settings->access_token)->get($messagesUrl, [
            'fields' => 'message,created_time,from,attachments,id',
            'limit' => 50,
        ]);

        if ($response->failed()) {
            Log::error('Meta API Sync Error', $response->json());
            return 0;
        }

        $remoteMessages = $response->json()['data'] ?? [];
        $addedCount = 0;

        foreach (array_reverse($remoteMessages) as $msgData) {
            $mid = $msgData['id'] ?? null;
            if (!$mid) continue;

            // Перевірка дублікатів через FacebookMessage
            if (FacebookMessage::where('mid', $mid)->exists()) {
                continue;
            }

            // Визначення від кого
            $isFromCustomer = isset($msgData['from']['id']) && $msgData['from']['id'] == $recipientId;
            $sentAt = $this->normalizeTimestamp($msgData['created_time'] ?? null);

            $text = $msgData['message'] ?? '';
            $hasAttachments = !empty($msgData['attachments']['data'] ?? []);

            FacebookMessage::create([
                'customer_id' => $customer->id,
                'mid' => $mid,
                'text' => $text !== '' ? $text : ($hasAttachments ? 'Вкладення' : ''),
                'attachments' => $msgData['attachments']['data'] ?? null,
                'is_from_customer' => $isFromCustomer,
                'platform' => $platform,
                'is_private' => true,
                'sent_at' => $sentAt,
                'status' => $isFromCustomer ? 'received' : 'sent',
                'is_read' => !$isFromCustomer,
                'created_at' => $sentAt,
                'updated_at' => $sentAt,
            ]);

            // Оновлюємо conversation
            $this->touchConversation(
                $customer->id,
                $platform,
                $text !== '' ? $text : ($hasAttachments ? 'Вкладення' : ''),
                $sentAt,
                $isFromCustomer
            );
            $addedCount++;
        }

        return $addedCount;
    }

    public function getUserProfile(string $psid): array
    {
        $settings = $this->getSettings();
        $response = Http::withToken($settings->access_token)->get($this->graphUrl("/{$psid}"), [
            'fields' => 'name,profile_pic',
        ]);

        return $response->ok() ? $response->json() : [];
    }

    public function touchConversation(
        int $customerId,
        string $platform,
        string $text,
        ?Carbon $sentAt,
        bool $isInbound
    ): void {
        // Використовуємо updateOrCreate, щоб отримати об'єкт
        $conversation = Conversation::updateOrCreate(
            ['customer_id' => $customerId, 'platform' => $platform],
            [
                'last_message_text' => mb_strimwidth($text, 0, 190, '...'),
                'last_message_at' => $sentAt,
                'status' => 'open',
                'updated_at' => now(), // Оновлюємо час редагування запису
            ]
        );

        if ($isInbound) {
            $conversation->increment('unread_count');
        } else {
            // Якщо це вихідне повідомлення — скидаємо лічильник непрочитаних
            $conversation->update(['unread_count' => 0]);
        }
    }

    private function getSettings()
    {
        $settings = DB::table('facebook_settings')->first();

        if (!$settings || !$settings->access_token) {
            throw new \RuntimeException('Meta token is missing.');
        }

        return $settings;
    }

    private function findThreadId(string $accessToken, string $recipientId, string $platform): ?string
    {
        $response = Http::withToken($accessToken)->get($this->graphUrl('/me/conversations'), [
            'platform' => $platform,
            'fields' => 'participants,updated_time',
            'limit' => 50,
        ]);

        if ($response->failed()) {
            return null;
        }

        foreach (($response->json()['data'] ?? []) as $conversation) {
            $participants = $conversation['participants']['data'] ?? [];
            foreach ($participants as $participant) {
                if (($participant['id'] ?? null) == $recipientId) {
                    return $conversation['id'] ?? null;
                }
            }
        }

        return null;
    }

    private function normalizeTimestamp(?string $timestamp): ?Carbon
    {
        if (!$timestamp) {
            return null;
        }
        $timezone = config('app.timezone', 'Europe/Kyiv');
        return Carbon::parse($timestamp)->timezone($timezone);
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/v19.0'.$path;
    }
}