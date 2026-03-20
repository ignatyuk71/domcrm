<?php

namespace App\Http\Controllers\Facebook;

use App\Http\Controllers\Controller;
use App\Models\ChatContact;
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

    public function handle(Request $request, MetaService $metaService, ChatService $chatService)
    {
        if (!$this->verifySignature($request)) {
            Log::warning('Facebook Webhook: invalid signature', [
                'ip' => $request->ip(),
            ]);

            return response('Invalid signature', 403);
        }

        try {
            $platform = ($request->input('object') ?? '') === 'instagram' ? 'instagram' : 'messenger';

            foreach ($request->input('entry', []) as $entry) {
                foreach ($entry['messaging'] ?? [] as $event) {
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
                }

                foreach ($entry['changes'] ?? [] as $change) {
                    if (in_array($change['field'] ?? '', ['feed', 'comments'], true)) {
                        Log::info('Chat comment webhook ignored', [
                            'platform' => $platform,
                            'field' => $change['field'] ?? null,
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
            return true;
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
        $profile = $metaService->getContactProfile((string) $externalUserId, $platform);
        $customer = $chatService->resolveCustomer($platform, (string) $externalUserId, $profile);
        $contact = $chatService->findOrCreateContact(
            $connection,
            $platform,
            (string) $externalUserId,
            $customer,
            $profile
        );

        if (!$contact->display_name || !$contact->avatar_path) {
            $contact = $chatService->syncContactProfile($contact, $metaService, $customer);
        }

        $conversation = $chatService->getOrCreateConversation($contact, $customer);
        $recentLocal = $isEcho ? $this->findRecentLocalOutbound($conversation->id, (string) ($message['text'] ?? '')) : null;
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
        $text = trim((string) ($message['text'] ?? ''));

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

        $originContext = $chatService->extractOriginContext($text, $platform);
        if ($originContext) {
            $originContext = $chatService->ensureOriginPreview($originContext);
            $chatService->syncMessageOrigin($storedMessage, $originContext);
            $chatService->syncConversationOrigin($conversation, $originContext);
            $storedMessage = $storedMessage->fresh(['parent', 'attachments']);
            $conversation = $conversation->fresh();
        }

        $chatService->updateConversationAfterMessage($conversation, $storedMessage, !$isEcho);
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

        if (!$contact?->conversation) {
            return;
        }

        $readAt = Carbon::createFromTimestampMs($watermark)->timezone(config('app.timezone'));

        ChatMessage::query()
            ->where('conversation_id', $contact->conversation->id)
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
