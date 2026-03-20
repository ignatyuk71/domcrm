<?php

namespace App\Services;

use App\Models\ChatContact;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatMessageAttachment;
use App\Models\ChatStage;
use App\Models\Customer;
use App\Models\MetaConnection;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ChatService
{
    public function getCurrentConnection(): MetaConnection
    {
        $connection = MetaConnection::current();

        if (!$connection) {
            throw new \RuntimeException('Активне Meta-підключення не знайдено.');
        }

        return $connection;
    }

    public function resolveCustomer(string $platform, string $externalUserId, array $profile = []): Customer
    {
        $field = $platform === 'instagram' ? 'instagram_user_id' : 'fb_user_id';
        $customer = Customer::query()->where($field, $externalUserId)->first();
        $name = $this->extractDisplayName($platform, $profile);
        [$firstName, $lastName] = $this->splitName($name);

        if ($customer) {
            $payload = [];

            if ($firstName && $this->shouldReplaceCustomerName($customer->first_name)) {
                $payload['first_name'] = $firstName;
            }

            if ($lastName && trim((string) $customer->last_name) === '') {
                $payload['last_name'] = $lastName;
            }

            if (
                $platform === 'instagram'
                && ($username = $this->extractUsername($profile))
                && trim((string) $customer->instagram_username) === ''
            ) {
                $payload['instagram_username'] = $username;
            }

            if ($payload !== []) {
                $customer->update($payload);
                $customer->refresh();
            }

            return $customer;
        }

        return Customer::create([
            $field => $externalUserId,
            'first_name' => $firstName ?: ($platform === 'instagram' ? 'Instagram User' : 'Facebook User'),
            'last_name' => $lastName,
            'instagram_username' => $platform === 'instagram'
                ? $this->extractUsername($profile)
                : null,
            'note' => $platform === 'instagram'
                ? 'Auto-created via Instagram'
                : 'Auto-created via Messenger',
        ]);
    }

    public function findOrCreateContact(
        MetaConnection $connection,
        string $platform,
        string $externalUserId,
        ?Customer $customer = null,
        array $profile = []
    ): ChatContact {
        $contact = ChatContact::query()->firstOrNew([
            'meta_connection_id' => $connection->id,
            'platform' => $platform,
            'external_user_id' => $externalUserId,
        ]);

        if ($customer) {
            $contact->customer_id = $customer->id;
        }

        $displayName = $this->extractDisplayName($platform, $profile);
        [$firstName, $lastName] = $this->splitName($displayName);

        $contact->external_username = $this->extractUsername($profile) ?: $contact->external_username;
        $contact->display_name = $displayName ?: $contact->display_name;
        $contact->first_name = $firstName ?: $contact->first_name;
        $contact->last_name = $lastName ?: $contact->last_name;

        if (!empty($profile)) {
            $contact->profile_payload = $profile;
            $contact->last_profile_sync_at = now();
        }

        $contact->save();

        return $contact;
    }

    public function syncContactProfile(
        ChatContact $contact,
        MetaService $metaService,
        ?Customer $customer = null
    ): ChatContact {
        try {
            $profile = $metaService->getContactProfile($contact->external_user_id, $contact->platform);
            if ($profile === []) {
                return $contact;
            }

            $contact = $this->findOrCreateContact(
                $contact->metaConnection,
                $contact->platform,
                $contact->external_user_id,
                $customer ?: $contact->customer,
                $profile
            );

            if (!empty($profile['profile_pic'])) {
                $avatarPath = $this->cacheProfileAvatar(
                    $contact->id,
                    (string) $profile['profile_pic']
                );

                if ($avatarPath) {
                    $contact->avatar_path = $avatarPath;
                }
                $contact->avatar_original_url = (string) $profile['profile_pic'];
            }

            $contact->save();

            if ($customer || $contact->customer) {
                $this->syncCustomerSnapshot($customer ?: $contact->customer, $contact, $profile);
            }

            return $contact->fresh();
        } catch (\Throwable $e) {
            Log::warning('Не вдалося синхронізувати профіль контакту чату', [
                'contact_id' => $contact->id,
                'platform' => $contact->platform,
                'error' => $e->getMessage(),
            ]);

            return $contact;
        }
    }

    public function hydrateConversationProfile(
        ChatConversation $conversation,
        MetaService $metaService,
        bool $force = false
    ): ChatConversation {
        $contact = $conversation->contact;
        if (!$contact) {
            return $conversation;
        }

        if (!$force && !$this->shouldRefreshContactProfile($contact, $conversation->customer)) {
            return $conversation;
        }

        $contact = $this->syncContactProfile($contact, $metaService, $conversation->customer);
        $conversation->setRelation('contact', $contact);

        if ($conversation->customer_id) {
            $freshCustomer = Customer::query()->find($conversation->customer_id);
            if ($freshCustomer) {
                $conversation->setRelation('customer', $freshCustomer);
            }
        }

        return $conversation;
    }

    public function buildConversationProfileSnapshot(
        ?ChatContact $contact,
        ?Customer $customer = null,
        bool $allowRecovery = false
    ): array {
        $displayName = $this->resolveDisplayName($contact, $customer);
        [$firstName, $lastName] = $this->resolveDisplayNameParts($contact, $customer);

        return [
            'display_name' => $displayName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'avatar_url' => $this->formatAvatarUrl($contact, $customer, $allowRecovery, $displayName),
        ];
    }

    public function getOrCreateConversation(
        ChatContact $contact,
        ?Customer $customer = null,
        ?string $externalThreadId = null
    ): ChatConversation {
        $defaultStageId = ChatStage::query()
            ->where('is_default', true)
            ->value('id');

        $conversation = ChatConversation::query()->firstOrNew([
            'contact_id' => $contact->id,
        ]);

        $conversation->meta_connection_id = $contact->meta_connection_id;
        $conversation->customer_id = $customer?->id ?: $contact->customer_id;
        $conversation->stage_id = $conversation->stage_id ?: $defaultStageId;
        $conversation->status = $conversation->status ?: 'open';
        $conversation->external_thread_id = $externalThreadId ?: $conversation->external_thread_id;
        $conversation->save();

        return $conversation;
    }

    public function storeMessage(
        ChatConversation $conversation,
        array $attributes,
        array $attachments = []
    ): ChatMessage {
        $externalMessageId = $attributes['external_message_id'] ?? null;
        $message = null;

        if ($externalMessageId) {
            $message = ChatMessage::query()
                ->where('external_message_id', $externalMessageId)
                ->first();
        }

        if (!$message) {
            $message = new ChatMessage();
        }

        $message->fill([
            'conversation_id' => $conversation->id,
            'parent_message_id' => $attributes['parent_message_id'] ?? null,
            'external_message_id' => $externalMessageId,
            'external_parent_message_id' => $attributes['external_parent_message_id'] ?? null,
            'direction' => $attributes['direction'] ?? 'inbound',
            'message_type' => $attributes['message_type']
                ?? $this->resolveMessageType($attributes['text'] ?? null, $attachments),
            'delivery_status' => $attributes['delivery_status']
                ?? ($attributes['direction'] ?? 'inbound') === 'outbound' ? 'sent' : 'delivered',
            'source' => $attributes['source'] ?? 'webhook',
            'text' => $attributes['text'] ?? null,
            'meta' => $attributes['meta'] ?? null,
            'sent_at' => $attributes['sent_at'] ?? now(),
            'delivered_at' => $attributes['delivered_at'] ?? null,
            'read_at' => $attributes['read_at'] ?? null,
            'failed_at' => $attributes['failed_at'] ?? null,
            'error_message' => $attributes['error_message'] ?? null,
        ]);
        $message->save();

        if ($attachments !== []) {
            $this->syncAttachments($message, $attachments);
        }

        return $message->fresh(['parent', 'attachments']);
    }

    public function updateConversationAfterMessage(
        ChatConversation $conversation,
        ChatMessage $message,
        bool $incrementUnread = true
    ): ChatConversation {
        $stamp = $message->sent_at ?: $message->created_at ?: now();
        $preview = $this->buildPreview($message);

        $conversation->last_message_id = $message->id;
        $conversation->last_message_preview = $preview;
        $conversation->last_message_at = $stamp;
        $conversation->status = 'open';

        if ($message->direction === 'inbound') {
            $conversation->last_inbound_at = $stamp;
            if ($incrementUnread) {
                $conversation->unread_count = (int) $conversation->unread_count + 1;
            }
            $this->moveConversationStage($conversation, ['no_stage', 'waiting_reply'], 'new');
        } else {
            $conversation->last_outbound_at = $stamp;
            $conversation->unread_count = 0;
            $this->moveConversationStage($conversation, ['no_stage'], 'waiting_reply');
        }

        $conversation->save();

        return $conversation->fresh(['contact', 'customer', 'stage', 'lastMessage']);
    }

    public function markConversationRead(ChatConversation $conversation): void
    {
        $conversation->update(['unread_count' => 0]);

        ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'delivery_status' => 'read',
            ]);
    }

    public function resolveConversationByCustomer(int $customerId, ?string $platform = null): ?ChatConversation
    {
        return ChatConversation::query()
            ->with(['contact', 'customer', 'stage', 'lastMessage', 'lastMessage.attachments'])
            ->where('customer_id', $customerId)
            ->where('status', '!=', 'archived')
            ->when($platform, fn ($query) => $query->whereHas(
                'contact',
                fn ($contactQuery) => $contactQuery->where('platform', $platform)
            ))
            ->orderByDesc('last_message_at')
            ->first();
    }

    public function resolveMessageByExternalId(?string $externalMessageId): ?ChatMessage
    {
        if (!$externalMessageId) {
            return null;
        }

        return ChatMessage::query()
            ->where('external_message_id', $externalMessageId)
            ->first();
    }

    public function formatAvatarUrl(
        ?ChatContact $contact,
        ?Customer $customer = null,
        bool $allowRecovery = false,
        ?string $fallbackName = null
    ): ?string
    {
        $contactAvatar = $this->normalizeAvatarPath($contact?->avatar_path);
        if ($contactAvatar) {
            return $contactAvatar;
        }

        $contactOriginalAvatar = $this->normalizeAvatarPath($contact?->avatar_original_url);
        if ($contactOriginalAvatar) {
            if ($allowRecovery && $contact) {
                $cachedAvatar = $this->cacheProfileAvatar($contact->id, $contactOriginalAvatar);
                if ($cachedAvatar) {
                    $contact->avatar_path = $cachedAvatar;
                    $contact->save();

                    return $this->normalizeAvatarPath($cachedAvatar);
                }
            }

            return $contactOriginalAvatar;
        }

        $legacyCustomerAvatar = $this->importLegacyCustomerAvatarToContact($contact, $customer);
        if ($legacyCustomerAvatar) {
            return $legacyCustomerAvatar;
        }

        if ($allowRecovery && $contact && $this->shouldRefreshContactAvatar($contact)) {
            try {
                $refreshedContact = $this->syncContactProfile($contact, app(MetaService::class), $customer);
                $refreshedAvatar = $this->normalizeAvatarPath($refreshedContact->avatar_path);
                if ($refreshedAvatar) {
                    return $refreshedAvatar;
                }

                $refreshedOriginalAvatar = $this->normalizeAvatarPath($refreshedContact->avatar_original_url);
                if ($refreshedOriginalAvatar) {
                    return $refreshedOriginalAvatar;
                }
            } catch (\Throwable $e) {
                Log::warning('Не вдалося оновити аватар контакту через fallback', [
                    'contact_id' => $contact->id,
                    'platform' => $contact->platform,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->buildFallbackAvatarUrl(
            $fallbackName ?: $this->resolveDisplayName($contact, $customer),
            $contact?->platform
        );
    }

    public function resolveDisplayName(?ChatContact $contact, ?Customer $customer = null): string
    {
        $contactName = $this->normalizeDisplayName((string) ($contact?->display_name ?? ''));
        $contactFullName = $this->normalizeDisplayName(trim((string) (($contact?->first_name ?? '') . ' ' . ($contact?->last_name ?? ''))));
        $customerName = $this->normalizeDisplayName((string) ($customer?->full_name ?? ''));

        if ($contactName !== '' && !$this->isPlaceholderName($contactName)) {
            return $contactName;
        }

        if ($contactFullName !== '' && !$this->isPlaceholderName($contactFullName)) {
            return $contactFullName;
        }

        if (
            $customerName !== ''
            && !$this->isPlaceholderName($customerName)
            && !$this->shouldPreferContactName($customerName, $contactName)
        ) {
            return $customerName;
        }

        $externalUsername = trim((string) ($contact?->external_username ?? ''));
        if ($externalUsername !== '') {
            return ltrim($externalUsername, '@');
        }

        return $contact?->platform === 'instagram' ? 'Instagram User' : 'Facebook User';
    }

    public function resolveDisplayNameParts(?ChatContact $contact, ?Customer $customer = null): array
    {
        $resolvedName = $this->resolveDisplayName($contact, $customer);
        [$firstName, $lastName] = $this->splitName($resolvedName);

        return [$firstName, $lastName];
    }

    private function shouldRefreshContactAvatar(ChatContact $contact): bool
    {
        if (!empty($contact->avatar_path)) {
            return false;
        }

        return $this->canRefreshContactProfile($contact);
    }

    public function shouldRefreshContactProfile(?ChatContact $contact, ?Customer $customer = null): bool
    {
        if (!$contact) {
            return false;
        }

        $displayName = trim((string) $contact->display_name);
        $contactFirstName = trim((string) $contact->first_name);
        $contactLastName = trim((string) $contact->last_name);
        $contactOriginalAvatar = $this->normalizeAvatarPath($contact->avatar_original_url);
        $legacyCustomerAvatar = $this->normalizeAvatarPath((string) ($customer?->fb_profile_pic ?? ''));
        $resolvedName = $displayName !== ''
            ? $displayName
            : trim($contactFirstName . ' ' . $contactLastName);

        $hasValidVisibleName = $resolvedName !== '' && !$this->isPlaceholderName($resolvedName);
        $hasVisibleAvatar = $this->normalizeAvatarPath($contact->avatar_path) !== null
            || $contactOriginalAvatar !== null
            || $legacyCustomerAvatar !== null;

        if ($hasValidVisibleName && $hasVisibleAvatar) {
            return false;
        }

        return $this->canRefreshContactProfile($contact);
    }

    private function shouldReplaceCustomerName(?string $firstName): bool
    {
        $firstName = trim((string) $firstName);

        return $firstName === ''
            || str_contains($firstName, 'Facebook User')
            || str_contains($firstName, 'Instagram User');
    }

    private function isPlaceholderName(?string $value): bool
    {
        $value = trim((string) $value);

        if ($value === '') {
            return false;
        }

        return str_contains($value, 'Facebook User')
            || str_contains($value, 'Instagram User');
    }

    private function shouldPreferContactName(string $customerName, string $contactName): bool
    {
        if ($contactName === '' || $customerName === '' || $customerName === $contactName) {
            return false;
        }

        $customerHasLatin = (bool) preg_match('/[A-Za-z]/u', $customerName);
        $customerHasCyrillic = (bool) preg_match('/[А-Яа-яЁёЇїІіЄєҐґ]/u', $customerName);
        if ($customerHasLatin && $customerHasCyrillic) {
            return true;
        }

        [$customerFirst] = $this->splitName($customerName);
        [$contactFirst] = $this->splitName($contactName);

        return $customerFirst !== null
            && $contactFirst !== null
            && mb_strtolower($customerFirst) !== mb_strtolower($contactFirst);
    }

    private function canRefreshContactProfile(ChatContact $contact): bool
    {
        if (!$contact->last_profile_sync_at) {
            return true;
        }

        return $contact->last_profile_sync_at->lt(now()->subHours(6));
    }

    private function normalizeAvatarPath(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return url(ltrim($path, '/'));
    }

    private function normalizeDisplayName(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        if ($value === '') {
            return '';
        }

        $parts = preg_split('/\s+/u', $value) ?: [];
        while (count($parts) >= 2) {
            $last = mb_strtolower((string) $parts[count($parts) - 1]);
            $previous = mb_strtolower((string) $parts[count($parts) - 2]);

            if ($last !== $previous) {
                break;
            }

            array_pop($parts);
        }

        return trim(implode(' ', $parts));
    }

    private function buildFallbackAvatarUrl(string $name, ?string $platform = null): string
    {
        $preparedName = $this->normalizeDisplayName($name);
        if ($preparedName === '' || $this->isPlaceholderName($preparedName)) {
            $preparedName = $platform === 'instagram' ? 'Instagram User' : 'Facebook User';
        }

        $background = $platform === 'instagram' ? 'E4405F' : '1877F2';

        return 'https://ui-avatars.com/api/?name=' . urlencode($preparedName)
            . '&background=' . $background
            . '&color=fff&bold=true&size=128';
    }

    private function importLegacyCustomerAvatarToContact(?ChatContact $contact, ?Customer $customer = null): ?string
    {
        $rawCustomerAvatar = trim((string) ($customer?->fb_profile_pic ?? ''));
        $customerAvatar = $this->normalizeAvatarPath($rawCustomerAvatar);
        if (!$customerAvatar) {
            return null;
        }

        if (!$contact) {
            return $customerAvatar;
        }

        $changed = false;

        if (!$this->normalizeAvatarPath($contact->avatar_original_url)) {
            $contact->avatar_original_url = $customerAvatar;
            $changed = true;
        }

        if (
            !$this->normalizeAvatarPath($contact->avatar_path)
            && !str_starts_with($rawCustomerAvatar, 'http://')
            && !str_starts_with($rawCustomerAvatar, 'https://')
        ) {
            $contact->avatar_path = ltrim($rawCustomerAvatar, '/');
            $changed = true;
        }

        if ($changed) {
            $contact->save();
        }

        return $this->normalizeAvatarPath($contact->avatar_path)
            ?: $this->normalizeAvatarPath($contact->avatar_original_url);
    }

    public function extractOriginContext(?string $text, ?string $platform = null): ?array
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }

        $normalized = mb_strtolower($text);
        $looksLikeComment = str_contains($normalized, 'коментар')
            || str_contains($normalized, 'comment')
            || str_contains($normalized, 'комментар')
            || str_contains($normalized, 'comment_id=');

        if (!$looksLikeComment) {
            return null;
        }

        preg_match('~https?://[^\s)]+~u', $text, $matches);
        $url = isset($matches[0]) ? rtrim($matches[0], '.,;') : null;
        $urlLower = mb_strtolower((string) $url);

        $resolvedPlatform = $platform;
        if ($urlLower !== '') {
            if (str_contains($urlLower, 'instagram.com')) {
                $resolvedPlatform = 'instagram';
            } elseif (str_contains($urlLower, 'facebook.com') || str_contains($urlLower, 'fb.com')) {
                $resolvedPlatform = 'messenger';
            }
        }

        $objectType = 'comment';
        if (
            str_contains($normalized, 'реклам')
            || str_contains($urlLower, 'ad_id=')
            || str_contains($urlLower, '/ads/')
            || str_contains($urlLower, 'adsmanager')
        ) {
            $objectType = 'ad';
        } elseif (
            str_contains($normalized, 'сторіс')
            || str_contains($normalized, 'сториз')
            || str_contains($normalized, 'story')
            || str_contains($urlLower, '/stories/')
            || str_contains($urlLower, 'story_fbid=')
        ) {
            $objectType = 'story';
        } elseif (
            str_contains($normalized, 'reel')
            || str_contains($urlLower, '/reel/')
            || str_contains($urlLower, '/reels/')
        ) {
            $objectType = 'reel';
        } elseif (
            str_contains($normalized, 'публікаці')
            || str_contains($normalized, 'допис')
            || str_contains($normalized, 'post')
            || str_contains($urlLower, '/posts/')
            || str_contains($urlLower, 'pfbid')
            || str_contains($urlLower, 'permalink.php')
        ) {
            $objectType = 'post';
        }

        $commentId = null;
        if ($url) {
            $query = parse_url($url, PHP_URL_QUERY);
            if (is_string($query)) {
                parse_str($query, $queryParams);
                $commentId = $queryParams['comment_id'] ?? $queryParams['reply_comment_id'] ?? null;
            }
        }

        $platformLabel = $resolvedPlatform === 'instagram' ? 'Instagram' : 'Facebook';
        $objectLabel = match ($objectType) {
            'ad' => 'реклами',
            'story' => 'сторіс',
            'reel' => 'reels',
            'post' => 'допису',
            default => 'коментаря',
        };

        return [
            'kind' => 'comment',
            'platform' => $resolvedPlatform,
            'object_type' => $objectType,
            'object_label' => $objectLabel,
            'summary' => "Коментар до {$objectLabel} {$platformLabel}",
            'url' => $url,
            'embed_url' => $this->buildOriginEmbedUrl($url, $objectType, $resolvedPlatform),
            'source_title' => match ($objectType) {
                'ad' => 'Реклама',
                'story' => 'Сторіс',
                'reel' => 'Reels',
                'post' => 'Пост',
                default => 'Джерело',
            },
            'source_display' => $this->formatOriginSourceDisplay($url),
            'comment_id' => $commentId,
        ];
    }

    public function ensureOriginPreview(array $originContext): array
    {
        $url = trim((string) ($originContext['url'] ?? ''));
        if ($url === '') {
            return $originContext;
        }

        $originContext['embed_url'] = $originContext['embed_url']
            ?? $this->buildOriginEmbedUrl(
                $url,
                (string) ($originContext['object_type'] ?? ''),
                (string) ($originContext['platform'] ?? '')
            );

        if (
            !empty($originContext['embed_url'])
            || !empty($originContext['preview_image_url'])
            || !empty($originContext['preview_title'])
            || array_key_exists('preview_unavailable', $originContext)
        ) {
            return $originContext;
        }

        $preview = $this->fetchOriginPreview($url);
        $originContext['preview_checked_at'] = now()->toDateTimeString();

        if (!$preview) {
            $originContext['preview_unavailable'] = true;

            return $originContext;
        }

        return array_merge($originContext, $preview, [
            'preview_unavailable' => false,
            'preview_checked_at' => now()->toDateTimeString(),
        ]);
    }

    public function syncConversationOrigin(ChatConversation $conversation, array $originContext): void
    {
        $meta = $conversation->meta ?: [];
        $meta['origin_context'] = $originContext;
        $conversation->meta = $meta;
        $conversation->save();
    }

    public function syncMessageOrigin(ChatMessage $message, array $originContext): void
    {
        $meta = $message->meta ?: [];
        $meta['origin_context'] = $originContext;
        $message->meta = $meta;
        $message->save();
    }

    private function syncCustomerSnapshot(Customer $customer, ChatContact $contact, array $profile): void
    {
        $payload = [];

        if ($contact->platform === 'instagram') {
            $payload['instagram_user_id'] = $contact->external_user_id;
            $payload['instagram_username'] = $contact->external_username ?: $customer->instagram_username;
        } else {
            $payload['fb_user_id'] = $contact->external_user_id;
        }

        $avatarForCustomer = $contact->avatar_path ?: $contact->avatar_original_url;
        if (!empty($avatarForCustomer)) {
            $payload['fb_profile_pic'] = $avatarForCustomer;
        }

        if ($contact->first_name && ($customer->first_name === null || str_contains($customer->first_name, 'User'))) {
            $payload['first_name'] = $contact->first_name;
        }

        if ($contact->last_name && ($customer->last_name === null || $customer->last_name === '')) {
            $payload['last_name'] = $contact->last_name;
        }

        if ($payload !== []) {
            $customer->update($payload);
        }
    }

    private function syncAttachments(ChatMessage $message, array $attachments): void
    {
        $message->attachments()->delete();

        foreach (array_values($attachments) as $index => $attachment) {
            $url = (string) ($attachment['url'] ?? '');
            $storagePath = null;
            $publicUrl = null;
            $originalUrl = $attachment['original_url'] ?? null;

            if ($url !== '') {
                if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
                    $publicUrl = $url;
                } else {
                    $storagePath = ltrim(preg_replace('#^chat/#', '', $url), '/');
                    $publicUrl = url(ltrim($url, '/'));
                }
            }

            ChatMessageAttachment::create([
                'message_id' => $message->id,
                'attachment_type' => $attachment['type'] ?? 'file',
                'storage_disk' => $storagePath ? 'chat_uploads' : null,
                'storage_path' => $storagePath,
                'original_url' => $originalUrl,
                'public_url' => $publicUrl,
                'mime_type' => $attachment['mime_type'] ?? null,
                'file_name' => $attachment['file_name'] ?? null,
                'file_size' => $attachment['file_size'] ?? null,
                'width' => $attachment['width'] ?? null,
                'height' => $attachment['height'] ?? null,
                'duration_seconds' => $attachment['duration_seconds'] ?? null,
                'sort_order' => $index,
                'meta' => $attachment['meta'] ?? null,
            ]);
        }
    }

    private function moveConversationStage(ChatConversation $conversation, array $fromCodes, string $toCode): void
    {
        $currentCode = $conversation->relationLoaded('stage')
            ? $conversation->stage?->code
            : ChatStage::query()->whereKey($conversation->stage_id)->value('code');
        if (!in_array($currentCode, $fromCodes, true)) {
            return;
        }

        $targetStageId = ChatStage::query()->where('code', $toCode)->value('id');
        if ($targetStageId) {
            $conversation->stage_id = $targetStageId;
        }
    }

    private function resolveMessageType(?string $text, array $attachments): string
    {
        if ($attachments === []) {
            return $text ? 'text' : 'system';
        }

        $firstType = $attachments[0]['type'] ?? 'file';

        return in_array($firstType, ['image', 'video', 'audio', 'file'], true)
            ? $firstType
            : 'file';
    }

    private function buildPreview(ChatMessage $message): string
    {
        $originContext = data_get($message->meta, 'origin_context')
            ?: $this->extractOriginContext($message->text, $message->conversation?->contact?->platform);

        if (!empty($originContext['summary'])) {
            return (string) $originContext['summary'];
        }

        $text = trim((string) $message->text);
        if ($text !== '') {
            return Str::limit($text, 190);
        }

        $attachment = $message->attachments->first();
        if (!$attachment) {
            return 'Системне повідомлення';
        }

        return match ($attachment->attachment_type) {
            'image' => 'Зображення',
            'video' => 'Відео',
            'audio' => 'Аудіо',
            default => 'Файл',
        };
    }

    private function extractDisplayName(string $platform, array $profile): string
    {
        if ($platform === 'instagram') {
            return trim((string) ($profile['name'] ?? $profile['username'] ?? ''));
        }

        $first = trim((string) ($profile['first_name'] ?? ''));
        $last = trim((string) ($profile['last_name'] ?? ''));
        $full = trim($first . ' ' . $last);

        return $full !== '' ? $full : trim((string) ($profile['name'] ?? ''));
    }

    private function extractUsername(array $profile): ?string
    {
        $username = trim((string) ($profile['username'] ?? ''));

        return $username !== '' ? $username : null;
    }

    private function splitName(string $name): array
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return [null, null];
        }

        $parts = preg_split('/\s+/', $trimmed, 2);

        return [$parts[0] ?? null, $parts[1] ?? null];
    }

    private function cacheProfileAvatar(int $contactId, string $remoteUrl): ?string
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
                return null;
            }

            $mimeType = $response->header('Content-Type');
            $extension = $this->guessExtension($remoteUrl, $mimeType);
            $relativePath = 'avatars/' . date('Y/m') . '/contact_' . $contactId . '_' . md5($remoteUrl) . '.' . strtolower($extension);

            Storage::disk('chat_uploads')->put($relativePath, $response->body());
            @chmod(public_path('chat/' . $relativePath), 0644);

            return 'chat/' . $relativePath;
        } catch (\Throwable $e) {
            Log::warning('Не вдалося завантажити аватар контакту', [
                'contact_id' => $contactId,
                'url' => $remoteUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
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
            ];

            $extension = $map[$mimeType] ?? '';
        }

        return $extension !== '' ? $extension : 'jpg';
    }

    private function fetchOriginPreview(string $url): ?array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; DomCRM/1.0; +https://domcrm.com.ua)',
                    'Accept-Language' => 'uk,en;q=0.8',
                ])
                ->get($url);

            if ($response->failed()) {
                return null;
            }

            $html = (string) $response->body();
            if ($html === '') {
                return null;
            }

            $title = $this->extractMetaTag($html, ['og:title', 'twitter:title'])
                ?: $this->extractHtmlTitle($html);
            $description = $this->extractMetaTag($html, ['og:description', 'description', 'twitter:description']);
            $imageUrl = $this->extractMetaTag($html, ['og:image', 'twitter:image']);
            $siteName = $this->extractMetaTag($html, ['og:site_name']);

            if ($imageUrl) {
                $imageUrl = $this->normalizePreviewUrl($url, $imageUrl);
                $imageUrl = $this->cacheOriginPreviewImage($imageUrl) ?: $imageUrl;
            }

            if (!$title && !$description && !$imageUrl) {
                return null;
            }

            return array_filter([
                'preview_title' => $title ? trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8')) : null,
                'preview_description' => $description ? trim(html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8')) : null,
                'preview_image_url' => $imageUrl,
                'preview_site_name' => $siteName ? trim(html_entity_decode($siteName, ENT_QUOTES | ENT_HTML5, 'UTF-8')) : null,
            ], fn ($value) => $value !== null && $value !== '');
        } catch (\Throwable $e) {
            Log::warning('Не вдалося отримати preview джерела коментаря', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function extractMetaTag(string $html, array $keys): ?string
    {
        foreach ($keys as $key) {
            $quotedKey = preg_quote($key, '~');
            $patterns = [
                '~<meta[^>]+(?:property|name)\s*=\s*["\']' . $quotedKey . '["\'][^>]+content\s*=\s*["\']([^"\']+)["\'][^>]*>~iu',
                '~<meta[^>]+content\s*=\s*["\']([^"\']+)["\'][^>]+(?:property|name)\s*=\s*["\']' . $quotedKey . '["\'][^>]*>~iu',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $html, $matches)) {
                    return html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }

        return null;
    }

    private function extractHtmlTitle(string $html): ?string
    {
        if (!preg_match('~<title[^>]*>(.*?)</title>~isu', $html, $matches)) {
            return null;
        }

        $title = trim(strip_tags(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return $title !== '' ? $title : null;
    }

    private function normalizePreviewUrl(string $baseUrl, string $previewUrl): string
    {
        $previewUrl = trim($previewUrl);
        if ($previewUrl === '') {
            return $previewUrl;
        }

        if (str_starts_with($previewUrl, 'http://') || str_starts_with($previewUrl, 'https://')) {
            return $previewUrl;
        }

        $parts = parse_url($baseUrl);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';

        if (str_starts_with($previewUrl, '//')) {
            return $scheme . ':' . $previewUrl;
        }

        if ($host === '') {
            return $previewUrl;
        }

        if (str_starts_with($previewUrl, '/')) {
            return $scheme . '://' . $host . $previewUrl;
        }

        return $scheme . '://' . $host . '/' . ltrim($previewUrl, '/');
    }

    private function cacheOriginPreviewImage(?string $remoteUrl): ?string
    {
        if (!$remoteUrl) {
            return null;
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; DomCRM/1.0; +https://domcrm.com.ua)',
                ])
                ->get($remoteUrl);

            if ($response->failed()) {
                return null;
            }

            $mimeType = $response->header('Content-Type');
            $extension = $this->guessExtension($remoteUrl, $mimeType);
            $relativePath = 'origin-previews/' . date('Y/m') . '/' . md5($remoteUrl) . '.' . strtolower($extension);

            Storage::disk('chat_uploads')->put($relativePath, $response->body());
            @chmod(public_path('chat/' . $relativePath), 0644);

            return url('chat/' . $relativePath);
        } catch (\Throwable $e) {
            Log::warning('Не вдалося закешувати preview джерела', [
                'url' => $remoteUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function buildOriginEmbedUrl(?string $url, string $objectType, string $platform): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $host = strtolower($host);

        if (
            in_array($objectType, ['post', 'comment'], true)
            && (str_contains($host, 'facebook.com') || str_contains($host, 'fb.watch'))
        ) {
            $normalizedUrl = $this->normalizeFacebookEmbedUrl($url);

            if ($normalizedUrl) {
                return 'https://www.facebook.com/plugins/post.php?href='
                    . urlencode($normalizedUrl)
                    . '&show_text=true&width=500';
            }
        }

        if (
            in_array($objectType, ['post', 'reel'], true)
            && ($platform === 'instagram' || str_contains($host, 'instagram.com'))
        ) {
            $normalizedUrl = $this->normalizeInstagramEmbedUrl($url);

            if ($normalizedUrl) {
                return $normalizedUrl;
            }
        }

        return null;
    }

    private function normalizeFacebookEmbedUrl(string $url): ?string
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;
        $path = $parts['path'] ?? null;

        if (!$host || !$path) {
            return null;
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        unset($query['comment_id'], $query['reply_comment_id'], $query['notif_t']);

        $normalized = ($parts['scheme'] ?? 'https') . '://' . $host . $path;

        if ($query !== []) {
            $normalized .= '?' . http_build_query($query);
        }

        return $normalized;
    }

    private function normalizeInstagramEmbedUrl(string $url): ?string
    {
        if (!preg_match('~https?://(?:www\.)?instagram\.com/(p|reel|reels)/([^/?#]+)/?~i', $url, $matches)) {
            return null;
        }

        $type = strtolower($matches[1]);
        $code = trim($matches[2]);

        if ($code === '') {
            return null;
        }

        if ($type === 'p') {
            return "https://www.instagram.com/p/{$code}/embed/captioned/";
        }

        return "https://www.instagram.com/reel/{$code}/embed/";
    }

    private function formatOriginSourceDisplay(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        $host = preg_replace('/^www\./i', '', $host);

        if ($path === '') {
            return $host !== '' ? $host : null;
        }

        $segments = array_values(array_filter(explode('/', $path)));
        $visibleSegments = array_slice($segments, 0, 3);
        $displayPath = implode('/', $visibleSegments);

        if (count($segments) > 3) {
            $displayPath .= '/…';
        }

        $display = trim(($host !== '' ? $host . '/' : '') . $displayPath, '/');

        return $display !== '' ? $display : null;
    }
}
