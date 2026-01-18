<?php

namespace App\Services;

use App\Models\FacebookMessage;
use App\Models\Conversation;
use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
            return !empty($attachment['url']);
        }));

        $payload = [
            'recipient' => ['id' => $recipientId],
            'messaging_type' => 'RESPONSE',
        ];

        if (!empty($validAttachments)) {
            $attachment = $validAttachments[0];
            $attachmentUrl = $attachment['url'] ?? '';
            if ($attachmentUrl !== '' && !str_starts_with($attachmentUrl, 'http')) {
                $attachmentUrl = url(ltrim($attachmentUrl, '/'));
            }
            $payload['message'] = [
                'attachment' => [
                    'type' => $attachment['type'] ?? 'image',
                    'payload' => [
                        'url' => $attachmentUrl,
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
            'fields' => 'message,created_time,from,attachments,reply_to,id',
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
            if (!$mid) {
                continue;
            }

            $existing = FacebookMessage::where('mid', $mid)->first();
            $replyToMid = $msgData['reply_to']['mid'] ?? null;
            $parentId = $replyToMid ? FacebookMessage::where('mid', $replyToMid)->value('id') : null;

            // Визначення від кого
            $isFromCustomer = isset($msgData['from']['id']) && $msgData['from']['id'] == $recipientId;
            $sentAt = $this->normalizeTimestamp($msgData['created_time'] ?? null);

            $text = $msgData['message'] ?? '';

            // Обробляємо вкладення так само, як у вебхуку, щоб зберегти їх локально
            $rawAttachments = $msgData['attachments']['data'] ?? [];
            $processedAttachments = [];

            if (!empty($rawAttachments) && (!$existing || empty($existing->attachments))) {
                foreach ($rawAttachments as $att) {
                    // Використовуємо наш метод завантаження
                    $processedAttachments[] = $this->processAttachment($att);
                }
            }
            $storedAttachments = $existing && !empty($existing->attachments)
                ? $existing->attachments
                : (!empty($processedAttachments) ? $processedAttachments : null);
            $hasAttachments = !empty($storedAttachments);

            $message = FacebookMessage::updateOrCreate(
                ['mid' => $mid],
                [
                    'customer_id' => $customer->id,
                    'parent_id' => $parentId,
                    'text' => $text !== '' ? $text : ($hasAttachments ? 'Вкладення' : ''),
                    'attachments' => $storedAttachments,
                    'is_from_customer' => $isFromCustomer,
                    'platform' => $platform,
                    'is_private' => true,
                    'sent_at' => $sentAt,
                    'status' => $isFromCustomer ? 'received' : 'sent',
                    'is_read' => !$isFromCustomer,
                    'created_at' => $sentAt ?? now(),
                    'updated_at' => $sentAt ?? now(),
                ]
            );

            // Оновлюємо conversation
            $this->touchConversation(
                $customer->id,
                $platform,
                $message->text ?? ($hasAttachments ? 'Вкладення' : ''),
                $message->sent_at,
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

    /**
     * Отримує та оновлює дані профілю (Ім'я, Фото) через Graph API.
     */
    public function updateCustomerProfile(Customer $customer): void
    {
        $settings = $this->getSettings();
        $socialId = $customer->instagram_user_id ?: $customer->fb_user_id;
        $isInsta = (bool) $customer->instagram_user_id;

        if (!$socialId) {
            return;
        }

        try {
            $fields = $isInsta ? 'name,profile_pic' : 'first_name,last_name,profile_pic';

            $response = Http::withToken($settings->access_token)
                ->get($this->graphUrl("/{$socialId}"), ['fields' => $fields]);

            if (!$response->successful()) {
                return;
            }

            $data = $response->json();
            $updateData = [];

            if ($isInsta) {
                $fullName = trim((string) ($data['name'] ?? ''));
                if ($fullName !== '') {
                    $parts = preg_split('/\s+/', $fullName, 2);
                    $updateData['first_name'] = $parts[0] ?? $customer->first_name;
                    $updateData['last_name'] = $parts[1] ?? $customer->last_name;
                }
            } else {
                if (!empty($data['first_name'])) {
                    $updateData['first_name'] = $data['first_name'];
                }
                if (!empty($data['last_name'])) {
                    $updateData['last_name'] = $data['last_name'];
                }
            }

            if (!empty($data['profile_pic'])) {
                $updateData['fb_profile_pic'] = $data['profile_pic'];
            }

            if (!empty($updateData)) {
                $customer->update($updateData);
            }
        } catch (\Throwable $e) {
            Log::warning('Meta profile sync failed', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Завантажує файл з URL Meta та зберігає напряму у public/chat/attachments.
     */
    public function processAttachment(array $attachment): array
    {
        $type = $attachment['type'] ?? 'file';
        $payload = $attachment['payload'] ?? [];
        $remoteUrl = $payload['url'] ?? ($attachment['url'] ?? null);

        if (!$remoteUrl || !str_starts_with($remoteUrl, 'http')) {
            return $attachment;
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        if ($appUrl !== '' && str_starts_with($remoteUrl, $appUrl)) {
            $path = parse_url($remoteUrl, PHP_URL_PATH);
            if ($path) {
                return [
                    'type' => $type,
                    'url' => ltrim($path, '/'),
                    'original_url' => $remoteUrl,
                ];
            }
        }

        try {
            $response = Http::timeout(10)->get($remoteUrl);
            if ($response->failed()) {
                throw new \RuntimeException('Failed to download file');
            }

            $fileContent = $response->body();

            $extension = 'jpg';
            $pathInfo = pathinfo(parse_url($remoteUrl, PHP_URL_PATH));
            if (!empty($pathInfo['extension'])) {
                $extension = $pathInfo['extension'];
            }

            $fileName = time().'_'.bin2hex(random_bytes(4)).'.'.strtolower($extension);
            $relativePath = 'attachments/' . date('Y/m/d') . '/' . $fileName;

            Storage::disk('chat_uploads')->put($relativePath, $fileContent);
            @chmod(public_path('chat/'.$relativePath), 0644);

            return [
                'type' => $type,
                'url' => 'chat/' . $relativePath,
                'original_url' => $remoteUrl,
            ];

        } catch (\Throwable $e) {
            Log::warning('Attachment download failed: ' . $e->getMessage());
            return [
                'type' => $type,
                'url' => $remoteUrl,
            ];
        }
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
