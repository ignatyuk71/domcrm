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

        $validAttachments = array_values(array_filter(array_map(function ($attachment) use ($platform) {
            return $this->normalizeOutgoingAttachmentForMeta($attachment, $platform);
        }, $validAttachments)));

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
     * Нормалізує вкладення до формату, який приймає Meta.
     *
     * @param  array<string, mixed>  $attachment
     * @return array<string, mixed>|null
     */
    private function normalizeOutgoingAttachmentForMeta(array $attachment, string $platform): ?array
    {
        $type = (string) ($attachment['type'] ?? 'image');
        $url = trim((string) ($attachment['url'] ?? ''));

        if ($url === '') {
            return null;
        }

        if ($type !== 'image') {
            return $attachment;
        }

        $extension = $this->detectImageExtension($url);
        if ($extension === null || in_array($extension, ['jpg', 'jpeg', 'png', 'gif'], true)) {
            return $attachment;
        }

        $compatibleUrl = $this->ensureMetaCompatibleImageUrl($url, $platform);
        if ($compatibleUrl === null) {
            Log::warning('Meta attachment normalization failed', [
                'platform' => $platform,
                'original_url' => $url,
                'extension' => $extension,
            ]);

            return null;
        }

        $attachment['url'] = $compatibleUrl;

        return $attachment;
    }

    private function detectImageExtension(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return $extension !== '' ? $extension : null;
    }

    private function ensureMetaCompatibleImageUrl(string $url, string $platform): ?string
    {
        $binary = $this->readAttachmentBinary($url);
        if ($binary === null) {
            return null;
        }

        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            Log::warning('Meta attachment conversion failed: image decode error', [
                'platform' => $platform,
                'original_url' => $url,
            ]);

            return null;
        }

        try {
            ob_start();
            imagejpeg($image, null, 90);
            $jpegBinary = ob_get_clean();
        } finally {
            imagedestroy($image);
        }

        if (!is_string($jpegBinary) || $jpegBinary === '') {
            Log::warning('Meta attachment conversion failed: jpeg encode error', [
                'platform' => $platform,
                'original_url' => $url,
            ]);

            return null;
        }

        $hash = sha1($platform . '|' . $url);
        $relativePath = 'meta/compatible/' . date('Y/m') . '/' . $hash . '.jpg';

        if (!Storage::disk('chat_uploads')->exists($relativePath)) {
            Storage::disk('chat_uploads')->put($relativePath, $jpegBinary);
            @chmod(public_path('chat/' . $relativePath), 0644);
        }

        Log::info('Meta attachment normalized to jpeg', [
            'platform' => $platform,
            'original_url' => $url,
            'normalized_url' => 'chat/' . $relativePath,
        ]);

        return url('chat/' . $relativePath);
    }

    private function readAttachmentBinary(string $url): ?string
    {
        $absolutePath = $this->resolveLocalAttachmentPath($url);
        if ($absolutePath !== null && is_file($absolutePath)) {
            $binary = @file_get_contents($absolutePath);

            return is_string($binary) && $binary !== '' ? $binary : null;
        }

        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            return null;
        }

        try {
            $response = Http::timeout(10)->get($url);
            if ($response->failed()) {
                Log::warning('Meta attachment download failed', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return null;
            }

            return $response->body();
        } catch (\Throwable $e) {
            Log::warning('Meta attachment download failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function resolveLocalAttachmentPath(string $url): ?string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            $appUrl = rtrim((string) config('app.url'), '/');
            if ($appUrl !== '' && str_starts_with($url, $appUrl)) {
                $path = parse_url($url, PHP_URL_PATH);

                return is_string($path) && $path !== '' ? public_path(ltrim($path, '/')) : null;
            }

            return null;
        }

        return public_path(ltrim($url, '/'));
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

        return $this->ingestThreadMessages(
            $conversation,
            $recipientId,
            $platform,
            (string) $settings->access_token,
            50
        );
    }

    /**
     * Підтягує недавні діалоги з Meta як fallback, якщо webhook пропустив вхідне повідомлення.
     */
    public function syncRecentConversations(string $platform = 'messenger', int $limit = 25, int $messagesLimit = 20): int
    {
        $settings = $this->getSettings();
        $chatService = app(ChatService::class);

        try {
            $response = Http::withToken($settings->access_token)->get($this->graphUrl('/me/conversations'), [
                'platform' => $platform,
                'fields' => 'participants{id,name},updated_time',
                'limit' => $limit,
            ]);
        } catch (\Throwable $e) {
            Log::error('Meta API recent conversations sync connection failed', [
                'platform' => $platform,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        if ($response->failed()) {
            Log::error('Meta API recent conversations sync failed', $response->json());

            return 0;
        }

        $addedCount = 0;
        foreach (($response->json()['data'] ?? []) as $thread) {
            $threadId = (string) ($thread['id'] ?? '');
            if ($threadId === '') {
                continue;
            }

            $participant = $this->resolveExternalParticipant($thread, $settings, $platform);
            $externalUserId = (string) ($participant['id'] ?? '');
            if ($externalUserId === '') {
                continue;
            }

            $profile = array_filter([
                'id' => $externalUserId,
                'name' => trim((string) ($participant['name'] ?? '')),
            ], static fn ($value) => $value !== null && $value !== '');

            $customer = $chatService->resolveCustomer($platform, $externalUserId, $profile);
            $contact = $chatService->findOrCreateContact($settings, $platform, $externalUserId, $customer, $profile);
            $conversation = $chatService->getOrCreateConversation($contact, $customer, $threadId);

            try {
                $addedCount += $this->ingestThreadMessages(
                    $conversation,
                    $externalUserId,
                    $platform,
                    (string) $settings->access_token,
                    $messagesLimit
                );
            } catch (\Throwable $e) {
                Log::warning('Meta recent conversation sync skipped thread after error', [
                    'platform' => $platform,
                    'thread_id' => $threadId,
                    'external_user_id' => $externalUserId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $addedCount;
    }

    public function getContactProfile(string $externalUserId, string $platform = 'messenger'): array
    {
        $settings = $this->getSettings();
        $participantSnapshot = $this->getParticipantProfileSnapshot($externalUserId, $platform);
        $graphProfile = $this->fetchGraphProfile(
            $externalUserId,
            $platform,
            (string) $settings->access_token
        );

        return array_merge($participantSnapshot, $graphProfile);
    }

    /**
     * Оновлює знімок профілю в customers і chat_contacts.
     */
    public function updateCustomerProfile(Customer $customer, ?string $platform = null): void
    {
        $connection = $this->getSettings();
        $platforms = [];

        if ($platform === 'messenger' && $customer->fb_user_id) {
            $platforms['messenger'] = (string) $customer->fb_user_id;
        } elseif ($platform === 'instagram' && $customer->instagram_user_id) {
            $platforms['instagram'] = (string) $customer->instagram_user_id;
        } elseif ($platform === null && $customer->fb_user_id) {
            $platforms['messenger'] = (string) $customer->fb_user_id;
        }
        if ($platform === null && $customer->instagram_user_id) {
            $platforms['instagram'] = (string) $customer->instagram_user_id;
        }

        foreach ($platforms as $platform => $externalUserId) {
            $profile = $this->getParticipantProfileSnapshot($externalUserId, $platform);
            $profile = array_merge(
                $profile,
                $this->fetchGraphProfile(
                    (string) $externalUserId,
                    (string) $platform,
                    (string) $connection->access_token
                )
            );

            if ($profile === []) {
                continue;
            }

            $payload = [];
            if ($platform === 'instagram') {
                $fullName = trim((string) ($profile['name'] ?? ''));
                if ($fullName !== '') {
                    [$firstName, $lastName] = $this->splitDisplayName($fullName);
                    $payload['first_name'] = $firstName ?: $customer->first_name;
                    $payload['last_name'] = $lastName ?: $customer->last_name;
                }

                if (!empty($profile['username'])) {
                    $payload['instagram_username'] = (string) $profile['username'];
                }
            } else {
                if (!empty($profile['first_name'])) {
                    $payload['first_name'] = (string) $profile['first_name'];
                }
                if (!empty($profile['last_name'])) {
                    $payload['last_name'] = (string) $profile['last_name'];
                }
            }

            if (!empty($profile['profile_pic'])) {
                $payload['fb_profile_pic'] = $this->cacheProfileAvatar($customer, (string) $profile['profile_pic'])
                    ?: (string) $profile['profile_pic'];
            }

            if ($payload !== []) {
                $customer->update($payload);
                $customer->refresh();
            }

            $chatService = app(ChatService::class);
            $contact = $chatService->findOrCreateContact(
                $connection,
                $platform,
                $externalUserId,
                $customer,
                $profile
            );

            if (!empty($profile['profile_pic'])) {
                $chatService->syncContactProfile($contact, $this, $customer);
            }
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

    /**
     * @param  array<string, mixed>  $thread
     * @return array<string, mixed>|null
     */
    private function resolveExternalParticipant(array $thread, MetaConnection $settings, string $platform): ?array
    {
        $participants = $thread['participants']['data'] ?? [];
        if (!is_array($participants)) {
            return null;
        }

        $ownIds = array_filter([
            (string) ($settings->facebook_page_id ?? ''),
            $platform === 'instagram' ? (string) ($settings->instagram_account_id ?? '') : '',
        ]);

        foreach ($participants as $participant) {
            if (!is_array($participant)) {
                continue;
            }

            $participantId = (string) ($participant['id'] ?? '');
            if ($participantId === '' || in_array($participantId, $ownIds, true)) {
                continue;
            }

            return $participant;
        }

        return null;
    }

    private function ingestThreadMessages(
        \App\Models\ChatConversation $conversation,
        string $recipientId,
        string $platform,
        string $accessToken,
        int $limit = 50
    ): int {
        $threadId = (string) $conversation->external_thread_id;
        if ($threadId === '') {
            return 0;
        }

        try {
            $response = Http::withToken($accessToken)->get($this->graphUrl("/{$threadId}/messages"), [
                'fields' => 'message,created_time,from,attachments,reply_to,id',
                'limit' => $limit,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Meta API Sync Connection Error', [
                'thread_id' => $threadId,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        if ($response->failed()) {
            Log::error('Meta API Sync Error', [
                'thread_id' => $threadId,
                'conversation_id' => $conversation->id,
                'response' => $response->json(),
            ]);

            return 0;
        }

        $chatService = app(ChatService::class);
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

            $originContext = $chatService->extractOriginContext($text, $platform);
            if ($originContext) {
                $originContext = $chatService->ensureOriginPreview($originContext);
                $chatService->syncMessageOrigin($message, $originContext);
                $chatService->syncConversationOrigin($conversation, $originContext);
                $message = $message->fresh(['parent', 'attachments']);
                $conversation = $conversation->fresh();
            }

            $conversation = $chatService->updateConversationAfterMessage(
                $conversation,
                $message,
                $isFromCustomer
            );
            $addedCount++;
        }

        return $addedCount;
    }

    private function getParticipantProfileSnapshot(string $externalUserId, string $platform): array
    {
        if ($platform !== 'messenger') {
            return [];
        }

        $settings = $this->getSettings();
        $threadId = $this->findThreadId($settings->access_token, $externalUserId, $platform);
        if (!$threadId) {
            return [];
        }

        $response = Http::withToken($settings->access_token)
            ->get($this->graphUrl("/{$threadId}"), ['fields' => 'participants{id,name,email,profile_pic,picture.type(large)}']);

        if ($response->failed()) {
            return [];
        }

        foreach (($response->json()['participants']['data'] ?? []) as $participant) {
            if ((string) ($participant['id'] ?? '') !== (string) $externalUserId) {
                continue;
            }

            $name = trim((string) ($participant['name'] ?? ''));
            if ($name === '') {
                return [];
            }

            [$firstName, $lastName] = $this->splitDisplayName($name);

            return array_filter([
                'id' => (string) ($participant['id'] ?? ''),
                'name' => $name,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'profile_pic' => $this->extractProfilePictureUrl($participant),
            ], static fn ($value) => $value !== null && $value !== '');
        }

        return [];
    }

    private function normalizeProfilePayload(array $profile): array
    {
        $profilePicture = $this->extractProfilePictureUrl($profile);
        if ($profilePicture && empty($profile['profile_pic'])) {
            $profile['profile_pic'] = $profilePicture;
        }

        return $profile;
    }

    private function fetchGraphProfile(string $externalUserId, string $platform, string $accessToken): array
    {
        if ($externalUserId === '' || $accessToken === '') {
            return [];
        }

        $profile = [];
        $response = Http::withToken($accessToken)
            ->get($this->graphUrl("/{$externalUserId}"), ['fields' => $this->profileFields($platform)]);

        if ($response->successful() && is_array($response->json())) {
            $profile = $this->normalizeProfilePayload($response->json());
        }

        if (empty($profile['profile_pic'])) {
            $profilePictureUrl = $this->fetchGraphProfilePictureUrl($externalUserId, $accessToken);
            if ($profilePictureUrl !== null) {
                $profile['profile_pic'] = $profilePictureUrl;
            }
        }

        return array_filter($profile, static fn ($value) => $value !== null && $value !== '');
    }

    private function profileFields(string $platform): string
    {
        return $platform === 'instagram'
            ? 'name,username,profile_pic,picture.type(large)'
            : 'first_name,last_name,name,profile_pic,picture.type(large)';
    }

    private function fetchGraphProfilePictureUrl(string $externalUserId, string $accessToken): ?string
    {
        if ($externalUserId === '' || $accessToken === '') {
            return null;
        }

        try {
            $response = Http::withToken($accessToken)
                ->get($this->graphUrl("/{$externalUserId}/picture"), [
                    'type' => 'large',
                    'redirect' => 'false',
                ]);

            if ($response->failed()) {
                return null;
            }

            $url = data_get($response->json(), 'data.url');
            if (is_string($url) && trim($url) !== '') {
                return trim($url);
            }

            $location = trim((string) $response->header('Location'));

            return $location !== '' ? $location : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractProfilePictureUrl(array $payload): ?string
    {
        $candidates = [
            $payload['profile_pic'] ?? null,
            data_get($payload, 'picture.data.url'),
            data_get($payload, 'picture.url'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function splitDisplayName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name), 2) ?: [];

        return [
            $parts[0] ?? null,
            $parts[1] ?? null,
        ];
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

    private function cacheProfileAvatar(Customer $customer, string $remoteUrl): ?string
    {
        if ($remoteUrl === '') {
            return null;
        }

        if (!str_starts_with($remoteUrl, 'http://') && !str_starts_with($remoteUrl, 'https://')) {
            return ltrim($remoteUrl, '/');
        }

        try {
            $response = Http::timeout(10)->get($remoteUrl);
            if ($response->failed()) {
                Log::warning('Profile avatar download failed', [
                    'customer_id' => $customer->id,
                    'url' => $remoteUrl,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $mimeType = $response->header('Content-Type');
            $extension = $this->guessExtension($remoteUrl, $mimeType);
            $relativePath = 'avatars/' . date('Y/m') . '/customer_' . $customer->id . '_' . md5($remoteUrl) . '.' . strtolower($extension);

            Storage::disk('chat_uploads')->put($relativePath, $response->body());
            @chmod(public_path('chat/' . $relativePath), 0644);

            return 'chat/' . $relativePath;
        } catch (\Throwable $e) {
            Log::warning('Profile avatar download failed', [
                'customer_id' => $customer->id,
                'url' => $remoteUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
