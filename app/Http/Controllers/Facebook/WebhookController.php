<?php

namespace App\Http\Controllers\Facebook;

use App\Http\Controllers\Controller;
use App\Models\ChatContact;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\MetaConnection;
use App\Services\ChatService;
use App\Services\MetaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function verify(Request $request)
    {
        $verifyToken = MetaConnection::query()
            ->where('provider', 'meta')
            ->where('is_active', true)
            ->latest('id')
            ->value('verify_token');

        if (!$verifyToken) {
            Log::error('Facebook Webhook: verify_token missing in meta_connections.');

            return response('Forbidden', 403);
        }

        $mode = $request->input('hub_mode') ?? $request->input('hub.mode');
        $token = $request->input('hub_verify_token') ?? $request->input('hub.verify_token');
        $challenge = $request->input('hub_challenge') ?? $request->input('hub.challenge');

        if ($mode === 'subscribe' && $token === $verifyToken) {
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }

    public function handle(
        Request $request,
        MetaService $metaService,
        ChatService $chatService
    )
    {
        if (!$this->verifySignature($request)) {
            Log::warning('Facebook Webhook: invalid signature', [
                'ip' => $request->ip(),
            ]);

            return response('Invalid signature', 403);
        }

        try {
            $platform = ($request->input('object') ?? '') === 'instagram' ? 'instagram' : 'messenger';
            $this->touchWebhookHeartbeat($platform);
            $this->logRawWebhookPayload($request, $platform);

            foreach ($request->input('entry', []) as $entry) {
                foreach ($entry['messaging'] ?? [] as $event) {
                    try {
                        if (isset($event['message'])) {
                            $this->processMessage($event, $platform, $metaService, $chatService);
                            continue;
                        }

                        if (isset($event['read'])) {
                            $this->processReadReceipt($event, $platform);
                            continue;
                        }

                        if (isset($event['delivery'])) {
                            $this->processDeliveryReceipt($event);
                        }
                    } catch (\Throwable $e) {
                        Log::error('Facebook messaging webhook event error', [
                            'platform' => $platform,
                            'error' => $e->getMessage(),
                            'event' => $event,
                        ]);
                    }
                }

                foreach ($entry['changes'] ?? [] as $change) {
                    try {
                        $processed = $this->processChange($entry, $change, $platform, $metaService, $chatService);

                        if (!$processed) {
                            Log::info('Chat change webhook ignored', [
                                'platform' => $platform,
                                'field' => $change['field'] ?? null,
                                'item' => data_get($change, 'value.item'),
                                'verb' => data_get($change, 'value.verb'),
                                'from_id' => data_get($change, 'value.from.id'),
                                'comment_id' => data_get($change, 'value.comment_id'),
                                'post_id' => data_get($change, 'value.post_id'),
                            ]);
                        }
                    } catch (\Throwable $e) {
                        Log::error('Facebook change webhook event error', [
                            'platform' => $platform,
                            'error' => $e->getMessage(),
                            'change' => $change,
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('Facebook Webhook Error: ' . $e->getMessage(), [
                'exception' => $e,
                'payload' => $request->all(),
            ]);
        }

        return response('EVENT_RECEIVED', 200);
    }

    private function logRawWebhookPayload(Request $request, string $platform): void
    {
        $entries = $request->input('entry', []);
        $changeFields = [];
        $changeItems = [];
        $messagingEvents = 0;
        $entryIds = [];

        foreach ($entries as $entry) {
            $entryId = trim((string) ($entry['id'] ?? ''));
            if ($entryId !== '' && !in_array($entryId, $entryIds, true)) {
                $entryIds[] = $entryId;
            }

            $messagingEvents += is_array($entry['messaging'] ?? null) ? count($entry['messaging']) : 0;

            foreach ($entry['changes'] ?? [] as $change) {
                $field = (string) ($change['field'] ?? '');
                if ($field !== '' && !in_array($field, $changeFields, true)) {
                    $changeFields[] = $field;
                }

                $item = (string) data_get($change, 'value.item', '');
                if ($item !== '' && !in_array($item, $changeItems, true)) {
                    $changeItems[] = $item;
                }
            }
        }

        Log::info('Meta webhook raw payload', [
            'platform' => $platform,
            'object' => $request->input('object'),
            'entry_ids' => $entryIds,
            'entry_count' => is_array($entries) ? count($entries) : 0,
            'messaging_events' => $messagingEvents,
            'change_fields' => $changeFields,
            'change_items' => $changeItems,
            'payload' => $request->all(),
        ]);
    }

    private function processChange(
        array $entry,
        array $change,
        string $platform,
        MetaService $metaService,
        ChatService $chatService
    ): bool {
        $field = (string) ($change['field'] ?? '');
        if (!in_array($field, ['feed', 'comments'], true)) {
            return false;
        }

        $value = $change['value'] ?? null;
        if (!is_array($value)) {
            return false;
        }

        $externalUserId = trim((string) (data_get($value, 'from.id') ?? data_get($value, 'sender_id') ?? ''));
        if ($externalUserId === '') {
            return false;
        }

        $externalMessageId = $this->resolveChangeExternalMessageId($field, $value);
        if ($externalMessageId === '') {
            return false;
        }

        if (ChatMessage::query()->where('external_message_id', $externalMessageId)->exists()) {
            return true;
        }

        $text = $this->resolveChangeText($value);
        if ($text === '') {
            return false;
        }

        $connection = $chatService->getCurrentConnection();
        $profile = $this->buildProfileFromChange($value, $platform);

        try {
            $graphProfile = $metaService->getContactProfile($externalUserId, $platform);
            if ($graphProfile !== []) {
                $profile = array_merge($profile, $graphProfile);
            }
        } catch (\Throwable $e) {
            Log::warning('Meta profile fetch failed for change webhook, using payload fallback', [
                'platform' => $platform,
                'external_user_id' => $externalUserId,
                'field' => $field,
                'error' => $e->getMessage(),
            ]);
        }

        $customer = $chatService->resolveCustomer($platform, $externalUserId, $profile);
        $contact = $chatService->findOrCreateContact(
            $connection,
            $platform,
            $externalUserId,
            $customer,
            $profile
        );

        $externalThreadId = $this->resolveChangeThreadId($entry, $value);
        $conversation = $chatService->getOrCreateConversation(
            $contact,
            $customer,
            $externalThreadId,
            ChatConversation::THREAD_KIND_COMMENT
        );

        $externalParentMessageId = $this->resolveChangeParentExternalMessageId($field, $value);
        $parentMessageId = $chatService->resolveMessageByExternalId($externalParentMessageId)?->id;
        $sentAt = $this->resolveChangeTimestamp($value);

        $meta = [
            'webhook_kind' => 'change',
            'webhook_field' => $field,
            'webhook_item' => data_get($value, 'item'),
            'webhook_verb' => data_get($value, 'verb'),
            'raw_change' => $change,
        ];

        $storedMessage = $chatService->storeMessage($conversation, [
            'parent_message_id' => $parentMessageId,
            'external_message_id' => $externalMessageId,
            'external_parent_message_id' => $externalParentMessageId,
            'direction' => 'inbound',
            'delivery_status' => 'delivered',
            'source' => 'webhook',
            'text' => $text,
            'meta' => $meta,
            'sent_at' => $sentAt,
        ]);

        $originContext = $this->buildOriginContextFromChange($platform, $value);
        if ($originContext) {
            $originContext = $chatService->ensureOriginPreview($originContext);
            $chatService->syncMessageOrigin($storedMessage, $originContext);
            $chatService->syncConversationOrigin($conversation, $originContext);
            $storedMessage = $storedMessage->fresh(['parent', 'attachments']);
            $conversation = $conversation->fresh();
        }

        $chatService->updateConversationAfterMessage($conversation, $storedMessage, true);

        return true;
    }

    private function verifySignature(Request $request): bool
    {
        $secret = (string) (
            config('services.meta.app_secret')
            ?: MetaConnection::query()
                ->where('provider', 'meta')
                ->where('is_active', true)
                ->latest('id')
                ->value('webhook_secret')
            ?: env('FB_WEBHOOK_SECRET')
        );

        if ($secret === '') {
            Log::error('Facebook Webhook: webhook secret missing, signature validation denied.');

            return false;
        }

        $payload = $request->getContent();
        $signature256 = (string) $request->header('X-Hub-Signature-256');
        if ($signature256 !== '' && str_starts_with($signature256, 'sha256=')) {
            $hash256 = hash_hmac('sha256', $payload, $secret);

            return hash_equals('sha256=' . $hash256, $signature256);
        }

        $signature = (string) $request->header('X-Hub-Signature');
        if ($signature !== '' && str_starts_with($signature, 'sha1=')) {
            $hash = hash_hmac('sha1', $payload, $secret);

            return hash_equals('sha1=' . $hash, $signature);
        }

        return false;
    }

    private function processMessage(
        array $event,
        string $platform,
        MetaService $metaService,
        ChatService $chatService
    ): void {
        $message = $event['message'] ?? null;
        if (!$message) {
            return;
        }

        $senderId = $event['sender']['id'] ?? null;
        $recipientId = $event['recipient']['id'] ?? null;
        $externalMessageId = $message['mid'] ?? null;
        $isEcho = (bool) ($message['is_echo'] ?? false);
        $externalUserId = $isEcho ? $recipientId : $senderId;

        if (!$externalUserId || !$externalMessageId) {
            return;
        }

        if (ChatMessage::query()->where('external_message_id', $externalMessageId)->exists()) {
            return;
        }

        $connection = $chatService->getCurrentConnection();
        $profile = [];

        try {
            $profile = $metaService->getContactProfile((string) $externalUserId, $platform);
        } catch (\Throwable $e) {
            Log::warning('Meta profile fetch failed for messaging webhook, using fallback profile', [
                'platform' => $platform,
                'external_user_id' => (string) $externalUserId,
                'external_message_id' => $externalMessageId,
                'error' => $e->getMessage(),
            ]);
        }

        $customer = $chatService->resolveCustomer($platform, (string) $externalUserId, $profile);

        if (!$isEcho) {
            try {
                $metaService->updateCustomerProfile($customer, $platform);
                $customer->refresh();
            } catch (\Throwable $e) {
                Log::warning('Meta customer profile update failed during messaging webhook', [
                    'platform' => $platform,
                    'external_user_id' => (string) $externalUserId,
                    'customer_id' => $customer->id,
                    'external_message_id' => $externalMessageId,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                $refreshedProfile = $metaService->getContactProfile((string) $externalUserId, $platform);
                if ($refreshedProfile !== []) {
                    $profile = $refreshedProfile;
                }
            } catch (\Throwable $e) {
                Log::warning('Meta profile refetch failed during messaging webhook', [
                    'platform' => $platform,
                    'external_user_id' => (string) $externalUserId,
                    'customer_id' => $customer->id,
                    'external_message_id' => $externalMessageId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $contact = $chatService->findOrCreateContact(
            $connection,
            $platform,
            (string) $externalUserId,
            $customer,
            $profile
        );

        if (!$contact->display_name || (!$contact->avatar_path && !$contact->avatar_original_url)) {
            $contact = $chatService->syncContactProfile($contact, $metaService, $customer);
        }

        // Тимчасовий точковий лог для нових Messenger-діалогів без фото від Meta.
        if (
            !$isEcho
            && $platform === 'messenger'
            && empty($profile['profile_pic'])
            && empty($contact->avatar_path)
            && empty($contact->avatar_original_url)
        ) {
            Log::warning('Messenger contact arrived without avatar payload', [
                'external_user_id' => (string) $externalUserId,
                'external_message_id' => $externalMessageId,
                'customer_id' => $customer->id,
                'contact_id' => $contact->id,
                'profile' => $profile,
                'event_sender_id' => (string) ($senderId ?? ''),
                'event_recipient_id' => (string) ($recipientId ?? ''),
            ]);
        }

        $text = trim((string) ($message['text'] ?? ''));
        $originContext = $chatService->extractOriginContext($text, $platform);
        $threadKind = $originContext
            ? ChatConversation::THREAD_KIND_COMMENT
            : ChatConversation::THREAD_KIND_DIRECT;
        $conversation = $chatService->getOrCreateConversation($contact, $customer, null, $threadKind);
        $recentLocal = $isEcho ? $this->findRecentLocalOutbound($conversation->id, $text) : null;
        $processedAttachments = [];

        foreach (($message['attachments'] ?? []) as $attachment) {
            $processedAttachments[] = $metaService->processAttachment($attachment);
        }

        if ($recentLocal) {
            $recentLocal->update([
                'external_message_id' => $externalMessageId,
                'delivery_status' => 'sent',
                'sent_at' => $recentLocal->sent_at ?: $this->resolveEventTimestamp($event),
            ]);

            if ($processedAttachments !== []) {
                app(ChatService::class)->storeMessage($conversation, [
                    'parent_message_id' => $recentLocal->parent_message_id,
                    'external_message_id' => $externalMessageId,
                    'direction' => 'outbound',
                    'message_type' => $recentLocal->message_type,
                    'delivery_status' => 'sent',
                    'source' => 'operator',
                    'text' => $recentLocal->text,
                    'sent_at' => $recentLocal->sent_at ?: $this->resolveEventTimestamp($event),
                ], $processedAttachments);

                $recentLocal->delete();
            }

            return;
        }

        $externalParentId = $message['reply_to']['mid'] ?? null;
        $parentMessageId = $chatService->resolveMessageByExternalId($externalParentId)?->id;

        $storedMessage = $chatService->storeMessage($conversation, [
            'parent_message_id' => $parentMessageId,
            'external_message_id' => $externalMessageId,
            'external_parent_message_id' => $externalParentId,
            'direction' => $isEcho ? 'outbound' : 'inbound',
            'message_type' => $text !== '' ? 'text' : null,
            'delivery_status' => $isEcho ? 'sent' : 'delivered',
            'source' => 'webhook',
            'text' => $text !== '' ? $text : null,
            'sent_at' => $this->resolveEventTimestamp($event),
        ], $processedAttachments);

        if ($originContext) {
            $originContext = $chatService->ensureOriginPreview($originContext);
            $chatService->syncMessageOrigin($storedMessage, $originContext);
            $chatService->syncConversationOrigin($conversation, $originContext);
            $storedMessage = $storedMessage->fresh(['parent', 'attachments']);
            $conversation = $conversation->fresh();
        }

        $conversation = $chatService->updateConversationAfterMessage($conversation, $storedMessage, !$isEcho);

    }

    private function resolveChangeExternalMessageId(string $field, array $value): string
    {
        $primaryId = trim((string) (
            $value['comment_id']
            ?? $value['message_id']
            ?? $value['id']
            ?? $value['mid']
            ?? ''
        ));

        if ($primaryId !== '') {
            return 'change:' . $field . ':' . $primaryId;
        }

        $hash = sha1(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $hash !== '' ? 'change:' . $field . ':' . $hash : '';
    }

    private function resolveChangeParentExternalMessageId(string $field, array $value): ?string
    {
        $parentId = trim((string) ($value['parent_id'] ?? ''));

        return $parentId !== '' ? 'change:' . $field . ':' . $parentId : null;
    }

    private function resolveChangeThreadId(array $entry, array $value): ?string
    {
        $threadId = trim((string) (
            $value['thread_id']
            ?? $value['post_id']
            ?? $value['comment_id']
            ?? ($entry['id'] ?? '')
        ));

        return $threadId !== '' ? $threadId : null;
    }

    private function resolveChangeText(array $value): string
    {
        $text = trim((string) (
            $value['message']
            ?? data_get($value, 'text')
            ?? ''
        ));

        return $text;
    }

    private function buildProfileFromChange(array $value, string $platform): array
    {
        $name = trim((string) (
            data_get($value, 'from.name')
            ?? data_get($value, 'sender_name')
            ?? ''
        ));

        if ($name === '') {
            return [];
        }

        if ($platform === 'instagram') {
            return ['name' => $name];
        }

        return ['name' => $name];
    }

    private function buildOriginContextFromChange(string $platform, array $value): ?array
    {
        $url = trim((string) (
            $value['permalink_url']
            ?? $value['link']
            ?? data_get($value, 'post.permalink_url')
            ?? ''
        ));

        $objectType = data_get($value, 'item') === 'comment' ? 'comment' : 'post';
        $platformLabel = $platform === 'instagram' ? 'Instagram' : 'Facebook';

        return [
            'kind' => 'comment',
            'platform' => $platform,
            'object_type' => $objectType,
            'object_label' => $objectType === 'comment' ? 'коментаря' : 'допису',
            'summary' => $objectType === 'comment'
                ? "Коментар {$platformLabel}"
                : "Повідомлення {$platformLabel}",
            'entry_point' => 'meta_change',
            'url' => $url !== '' ? $url : null,
            'source_title' => $objectType === 'comment' ? 'Коментар' : 'Джерело',
            'source_display' => $url !== '' ? $url : null,
            'comment_id' => $value['comment_id'] ?? null,
        ];
    }

    private function resolveChangeTimestamp(array $value): Carbon
    {
        $timestamp = $value['created_time'] ?? $value['time'] ?? null;

        if (is_numeric($timestamp)) {
            return Carbon::createFromTimestampMs((int) $timestamp);
        }

        if (is_string($timestamp) && trim($timestamp) !== '') {
            try {
                return Carbon::parse($timestamp);
            } catch (\Throwable) {
                // Нічого не робимо, нижче буде now().
            }
        }

        return now();
    }

    private function processReadReceipt(array $event, string $platform): void
    {
        $externalUserId = (string) ($event['sender']['id'] ?? '');
        $watermark = data_get($event, 'read.watermark');

        if ($externalUserId === '' || !$watermark) {
            return;
        }

        $contact = ChatContact::query()
            ->where('platform', $platform)
            ->where('external_user_id', $externalUserId)
            ->first();

        if (!$contact) {
            return;
        }

        $conversationIds = $contact->conversations()->pluck('id');
        if ($conversationIds->isEmpty()) {
            return;
        }

        $readAt = Carbon::createFromTimestampMs($watermark)->timezone(config('app.timezone'));

        ChatMessage::query()
            ->whereIn('conversation_id', $conversationIds)
            ->where('direction', 'outbound')
            ->where(function ($query) {
                $query->whereNull('read_at')
                    ->orWhere('delivery_status', '!=', 'read');
            })
            ->where(function ($query) use ($readAt) {
                $query->whereNull('sent_at')
                    ->orWhere('sent_at', '<=', $readAt);
            })
            ->update([
                'delivery_status' => 'read',
                'read_at' => $readAt,
                'delivered_at' => $readAt,
            ]);
    }

    private function touchWebhookHeartbeat(string $platform): void
    {
        $connection = MetaConnection::current();
        if (!$connection) {
            return;
        }

        $connection->forceFill([
            'last_webhook_at' => now(),
            'last_webhook_platform' => $platform,
        ])->save();
    }

    private function processDeliveryReceipt(array $event): void
    {
        $messageIds = data_get($event, 'delivery.mids', []);
        $watermark = data_get($event, 'delivery.watermark');
        if (!is_array($messageIds) || $messageIds === []) {
            return;
        }

        $deliveredAt = $watermark
            ? Carbon::createFromTimestampMs($watermark)->timezone(config('app.timezone'))
            : now();

        ChatMessage::query()
            ->whereIn('external_message_id', $messageIds)
            ->where('direction', 'outbound')
            ->where(function ($query) {
                $query->whereNull('delivered_at')
                    ->orWhere('delivery_status', 'pending')
                    ->orWhere('delivery_status', 'sent');
            })
            ->update([
                'delivery_status' => 'delivered',
                'delivered_at' => $deliveredAt,
            ]);
    }

    private function resolveEventTimestamp(array $event): Carbon
    {
        if (!empty($event['timestamp'])) {
            return Carbon::createFromTimestampMs($event['timestamp'])->timezone(config('app.timezone'));
        }

        return now();
    }

    private function findRecentLocalOutbound(int $conversationId, string $text): ?ChatMessage
    {
        return ChatMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('direction', 'outbound')
            ->whereNull('external_message_id')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->when(
                trim($text) !== '',
                fn ($query) => $query->where('text', trim($text))
            )
            ->latest('id')
            ->first();
    }
}
