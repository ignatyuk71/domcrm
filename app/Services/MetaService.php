<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\Customer;
use App\Models\MetaConnection;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MetaService
{
    /**
     * Відправка повідомлення в Meta.
     */
    public function sendMessage(
        Customer $customer,
        string $text,
        array $attachments = [],
        string $platform = 'messenger',
        ?string $recipientId = null
    ): ?array {
        $settings = $this->getSettings();
        $recipientId = $recipientId ?: $this->resolveRecipientId($customer, $platform);

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

        if ($validAttachments !== []) {
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
            if (trim($text) === '') {
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
     * Стягування історії для конкретного каналу клієнта.
     */
    public function syncHistory(Customer $customer, ?string $platform = null): int
    {
        $settings = $this->getSettings();
        $platform = $platform ?: ($customer->instagram_user_id ? 'instagram' : 'messenger');
        $recipientId = $this->resolveRecipientId($customer, $platform);

        if (!$recipientId) {
            return 0;
        }

        $chatService = app(ChatService::class);
        $profile = $this->getContactProfile($recipientId, $platform);
        $contact = $chatService->findOrCreateContact($settings, $platform, $recipientId, $customer, $profile);
        if ($profile !== []) {
            $contact = $chatService->syncContactProfile($contact, $this, $customer);
        }

        $threadId = $this->findThreadId($settings->access_token, $recipientId, $platform);
        $conversation = $chatService->getOrCreateConversation($contact, $customer, $threadId);

        if (!$threadId) {
            return 0;
        }

        $response = Http::withToken($settings->access_token)->get($this->graphUrl("/{$threadId}/messages"), [
            'fields' => 'message,created_time,from,attachments,reply_to,id',
            'limit' => 50,
        ]);

        if ($response->failed()) {
            Log::error('Meta API Sync Error', $response->json());

            return 0;
        }

        $addedCount = 0;
        foreach (array_reverse($response->json()['data'] ?? []) as $msgData) {
            $externalMessageId = $msgData['id'] ?? null;
            if (!$externalMessageId || $chatService->resolveMessageByExternalId($externalMessageId)) {
                continue;
            }

            $externalParentId = $msgData['reply_to']['mid'] ?? null;
            $parentMessageId = $chatService->resolveMessageByExternalId($externalParentId)?->id;
            $isFromCustomer = isset($msgData['from']['id']) && (string) $msgData['from']['id'] === (string) $recipientId;
            $sentAt = $this->normalizeTimestamp($msgData['created_time'] ?? null) ?: now();

            $processedAttachments = [];
            foreach (($msgData['attachments']['data'] ?? []) as $attachment) {
                $processedAttachments[] = $this->processAttachment($attachment);
            }

            $text = trim((string) ($msgData['message'] ?? ''));
            $message = $chatService->storeMessage($conversation, [
                'parent_message_id' => $parentMessageId,
                'external_message_id' => $externalMessageId,
                'external_parent_message_id' => $externalParentId,
                'direction' => $isFromCustomer ? 'inbound' : 'outbound',
                'delivery_status' => $isFromCustomer ? 'delivered' : 'sent',
                'source' => 'sync',
                'text' => $text !== '' ? $text : null,
                'sent_at' => $sentAt,
            ], $processedAttachments);

            $conversation = $chatService->updateConversationAfterMessage(
                $conversation,
                $message,
                $isFromCustomer
            );
            $addedCount++;
        }

        return $addedCount;
    }

    public function getContactProfile(string $externalUserId, string $platform = 'messenger'): array
    {
        $fields = $platform === 'instagram'
            ? 'name,username,profile_pic'
            : 'first_name,last_name,profile_pic';

        $response = Http::withToken($this->getSettings()->access_token)
            ->get($this->graphUrl("/{$externalUserId}"), ['fields' => $fields]);

        return $response->ok() ? $response->json() : [];
    }

    /**
     * Оновлює знімок профілю в customers і chat_contacts.
     */
    public function updateCustomerProfile(Customer $customer): void
    {
        $chatService = app(ChatService::class);
        $connection = $this->getSettings();

        $platforms = [];
        if ($customer->fb_user_id) {
            $platforms['messenger'] = (string) $customer->fb_user_id;
        }
        if ($customer->instagram_user_id) {
            $platforms['instagram'] = (string) $customer->instagram_user_id;
        }

        foreach ($platforms as $platform => $externalUserId) {
            $contact = $chatService->findOrCreateContact(
                $connection,
                $platform,
                $externalUserId,
                $customer
            );

            $chatService->syncContactProfile($contact, $this, $customer);
        }
    }

    /**
     * Завантажує вкладення Meta і зберігає його локально.
     */
    public function processAttachment(array $attachment): array
    {
        $type = $this->resolveAttachmentType($attachment);
        $remoteUrl = $this->extractAttachmentUrl($attachment);

        if (!$remoteUrl) {
            return $attachment;
        }

        if (!str_starts_with($remoteUrl, 'http')) {
            return [
                'type' => $type,
                'url' => $remoteUrl,
            ];
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
                Log::warning('Attachment download failed', [
                    'url' => $remoteUrl,
                    'status' => $response->status(),
                    'body' => Str::limit($response->body(), 200),
                ]);

                return [
                    'type' => $type,
                    'url' => $remoteUrl,
                ];
            }

            $mimeType = data_get($attachment, 'mime_type')
                ?? data_get($attachment, 'payload.mime_type');
            $extension = $this->guessExtension($remoteUrl, $mimeType);
            $fileName = time() . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($extension);
            $relativePath = 'attachments/' . date('Y/m/d') . '/' . $fileName;

            Storage::disk('chat_uploads')->put($relativePath, $response->body());
            @chmod(public_path('chat/' . $relativePath), 0644);

            return [
                'type' => $type,
                'url' => 'chat/' . $relativePath,
                'original_url' => $remoteUrl,
                'mime_type' => $mimeType,
            ];
        } catch (\Throwable $e) {
            Log::warning('Attachment download failed', [
                'url' => $remoteUrl,
                'error' => $e->getMessage(),
            ]);

            return [
                'type' => $type,
                'url' => $remoteUrl,
            ];
        }
    }

    private function extractAttachmentUrl(array $attachment): ?string
    {
        $candidates = [
            data_get($attachment, 'payload.url'),
            data_get($attachment, 'payload.image_data.url'),
            data_get($attachment, 'payload.video_data.url'),
            data_get($attachment, 'payload.file_url'),
            data_get($attachment, 'image_data.url'),
            data_get($attachment, 'video_data.url'),
            data_get($attachment, 'file_url'),
            data_get($attachment, 'url'),
        ];

        foreach ($candidates as $url) {
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    private function resolveAttachmentType(array $attachment): string
    {
        $type = data_get($attachment, 'type');
        if (is_string($type) && $type !== '') {
            return $type;
        }

        $mimeType = data_get($attachment, 'mime_type')
            ?? data_get($attachment, 'payload.mime_type');

        if (is_string($mimeType)) {
            if (str_starts_with($mimeType, 'image/')) {
                return 'image';
            }
            if (str_starts_with($mimeType, 'video/')) {
                return 'video';
            }
            if (str_starts_with($mimeType, 'audio/')) {
                return 'audio';
            }
        }

        return 'file';
    }

    private function guessExtension(?string $remoteUrl, ?string $mimeType): string
    {
        $extension = '';
        if ($remoteUrl) {
            $path = parse_url($remoteUrl, PHP_URL_PATH);
            if ($path) {
                $pathInfo = pathinfo($path);
                if (!empty($pathInfo['extension'])) {
                    $extension = $pathInfo['extension'];
                }
            }
        }

        if ($extension === '' && is_string($mimeType)) {
            $map = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                'video/mp4' => 'mp4',
                'video/quicktime' => 'mov',
                'video/webm' => 'webm',
                'audio/mpeg' => 'mp3',
                'audio/wav' => 'wav',
                'application/pdf' => 'pdf',
            ];
            $extension = $map[$mimeType] ?? '';
        }

        return $extension !== '' ? $extension : 'jpg';
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
                if ((string) ($participant['id'] ?? '') === (string) $recipientId) {
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

        return Carbon::parse($timestamp)->timezone(config('app.timezone', 'Europe/Kyiv'));
    }

    private function resolveRecipientId(Customer $customer, string $platform): ?string
    {
        if ($platform === 'instagram') {
            return $customer->instagram_user_id ?: $customer->fb_user_id;
        }

        return $customer->fb_user_id ?: $customer->instagram_user_id;
    }

    private function getSettings(): MetaConnection
    {
        $settings = MetaConnection::query()
            ->where('provider', 'meta')
            ->where('is_active', true)
            ->whereNotNull('access_token')
            ->latest('id')
            ->first();

        if ($settings?->access_token) {
            return $settings;
        }

        throw new \RuntimeException('Meta token is missing in meta_connections.');
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/' . config('services.meta.graph_version', 'v19.0') . $path;
    }
}
