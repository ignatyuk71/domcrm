<?php

namespace App\Http\Controllers;

use App\Models\ChatContact;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatStage;
use App\Models\Customer;
use App\Services\ChatService;
use App\Services\MetaService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ChatApiController extends Controller
{
    public function __construct(
        private readonly ChatService $chatService
    ) {
    }

    public function list(): JsonResponse
    {
        try {
            $conversations = ChatConversation::query()
                ->where('status', '!=', 'archived')
                ->with(['contact', 'customer', 'stage', 'lastMessage.attachments'])
                ->orderByDesc('last_message_at')
                ->paginate(20);

            $conversations->getCollection()->transform(
                fn (ChatConversation $conversation) => $this->formatConversation($conversation)
            );

            return response()->json($conversations);
        } catch (\Throwable $e) {
            Log::error('Chat list failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['data' => []]);
        }
    }

    public function funnel(): JsonResponse
    {
        $conversations = ChatConversation::query()
            ->where('status', '!=', 'archived')
            ->with(['contact', 'customer', 'stage', 'lastMessage.attachments'])
            ->orderByDesc('last_message_at')
            ->get();

        $groups = ['none' => []];
        foreach ($this->availableStages() as $stageCode) {
            $groups[$stageCode] = [];
        }

        foreach ($conversations as $conversation) {
            $payload = $this->formatConversation($conversation);
            $stageKey = $payload['stage'] ?: 'none';
            $groups[$stageKey][] = $payload;
        }

        return response()->json(['data' => $groups]);
    }

    public function listConversationTags(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    public function updateStage(Request $request, ChatConversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'stage' => ['nullable', 'string', Rule::in($this->availableStages())],
        ]);

        $targetCode = $validated['stage'] ?? 'no_stage';
        $stageId = ChatStage::query()
            ->where('code', $targetCode)
            ->value('id');

        if (!$stageId) {
            return response()->json(['error' => 'Stage not found'], 404);
        }

        $conversation->update(['stage_id' => $stageId]);

        return response()->json([
            'stage' => $targetCode === 'no_stage' ? null : $targetCode,
        ]);
    }

    public function updateTags(Request $request, ChatConversation $conversation): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    public function archiveConversation(ChatConversation $conversation): JsonResponse
    {
        $conversation->update([
            'status' => 'archived',
            'closed_at' => now(),
            'unread_count' => 0,
        ]);

        return response()->json(['success' => true]);
    }

    public function showByCustomer(Request $request, int $customerId): JsonResponse
    {
        $platform = $request->query('platform');
        $conversation = $this->chatService->resolveConversationByCustomer($customerId, $platform);

        if (!$conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        return response()->json(['data' => $this->formatConversation($conversation)]);
    }

    public function messages(Request $request, int $id, MetaService $metaService): JsonResponse
    {
        $customer = Customer::find($id);
        if (!$customer) {
            return response()->json(['error' => 'Customer not found'], 404);
        }

        $platform = $request->query('platform');
        $conversation = $this->ensureConversationForCustomer($customer, $platform, $metaService);

        if (!$conversation) {
            return response()->json(['data' => []]);
        }

        try {
            $hasMessages = ChatMessage::query()
                ->where('conversation_id', $conversation->id)
                ->exists();

            if (!$hasMessages) {
                $metaService->syncHistory($customer, $conversation->contact->platform);
                $conversation = $conversation->fresh(['contact', 'customer', 'stage', 'lastMessage.attachments']);
            }

            $messages = ChatMessage::query()
                ->with(['parent.attachments', 'attachments'])
                ->where('conversation_id', $conversation->id)
                ->orderByRaw('COALESCE(sent_at, created_at) asc')
                ->get()
                ->map(fn (ChatMessage $message) => $this->formatMessage($message));

            $this->chatService->markConversationRead($conversation);

            return response()->json(['data' => $messages]);
        } catch (\Throwable $e) {
            Log::error('Chat messages query failed', [
                'customer_id' => $id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Internal Server Error'], 500);
        }
    }

    public function send(Request $request, MetaService $metaService): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'text' => 'nullable|string',
            'files' => 'nullable|array',
            'files.*' => 'file|mimes:jpg,jpeg,png,webp,gif,heic,heif,pdf,mp4,mov,webm,mp3,wav|max:10240',
            'remote_urls' => 'nullable|array',
            'remote_urls.*' => ['string', 'max:2048', function ($attribute, $value, $fail) {
                $url = trim((string) $value);
                if ($url === '') {
                    return;
                }
                if (preg_match('/^(javascript|data):/i', $url)) {
                    $fail('Invalid url');
                    return;
                }
                if (!str_starts_with($url, 'http') && !str_starts_with($url, '/')) {
                    $fail('Invalid url');
                }
            }],
            'platform' => 'nullable|string|in:messenger,instagram',
        ]);

        if (
            empty($validated['text'])
            && !$request->hasFile('files')
            && empty($validated['remote_urls'])
        ) {
            return response()->json(['error' => 'Повідомлення порожнє'], 422);
        }

        $customer = Customer::find($validated['customer_id']);
        if (!$customer) {
            return response()->json(['error' => 'Customer not found'], 404);
        }

        $platform = ($validated['platform'] ?? null)
            ?: ($customer->instagram_user_id ? 'instagram' : 'messenger');
        $conversation = $this->ensureConversationForCustomer($customer, $platform, $metaService);

        if (!$conversation) {
            return response()->json(['error' => 'Не вдалося знайти або створити діалог.'], 422);
        }

        $contact = $conversation->contact;
        $attachments = $this->collectOutgoingAttachments($request, $validated['remote_urls'] ?? []);
        $createdMessages = [];
        $sentAt = Carbon::now(config('app.timezone', 'Europe/Kyiv'));

        try {
            if ($attachments !== []) {
                foreach ($attachments as $index => $attachment) {
                    $text = $index === 0 ? ($validated['text'] ?? '') : '';

                    $metaResult = $metaService->sendMessage(
                        $customer,
                        $text,
                        [$attachment['meta_payload']],
                        $platform,
                        $contact->external_user_id
                    );

                    if (!$metaResult) {
                        return response()->json(['error' => 'Не вдалося відправити повідомлення через Meta API.'], 500);
                    }

                    $message = $this->chatService->storeMessage($conversation, [
                        'direction' => 'outbound',
                        'external_message_id' => $metaResult['message_id'] ?? null,
                        'delivery_status' => 'sent',
                        'source' => 'operator',
                        'text' => $text !== '' ? $text : null,
                        'sent_at' => $sentAt,
                    ], [$attachment['stored_attachment']]);

                    $conversation = $this->chatService->updateConversationAfterMessage($conversation, $message, false);
                    $createdMessages[] = $this->formatMessage($message);
                }
            } else {
                $metaResult = $metaService->sendMessage(
                    $customer,
                    $validated['text'] ?? '',
                    [],
                    $platform,
                    $contact->external_user_id
                );

                if (!$metaResult) {
                    return response()->json(['error' => 'Не вдалося відправити повідомлення через Meta API.'], 500);
                }

                $message = $this->chatService->storeMessage($conversation, [
                    'direction' => 'outbound',
                    'external_message_id' => $metaResult['message_id'] ?? null,
                    'delivery_status' => 'sent',
                    'source' => 'operator',
                    'text' => $validated['text'] ?? null,
                    'sent_at' => $sentAt,
                ]);

                $conversation = $this->chatService->updateConversationAfterMessage($conversation, $message, false);
                $createdMessages[] = $this->formatMessage($message);
            }

            return response()->json(['data' => $createdMessages]);
        } catch (\Throwable $e) {
            Log::error('Chat send failed', [
                'customer_id' => $customer->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'DB Error'], 500);
        }
    }

    public function markRead(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'platform' => 'nullable|string|in:messenger,instagram',
        ]);

        $conversations = ChatConversation::query()
            ->with('contact')
            ->where('customer_id', $validated['customer_id'])
            ->when(
                !empty($validated['platform']),
                fn ($query) => $query->whereHas(
                    'contact',
                    fn ($contactQuery) => $contactQuery->where('platform', $validated['platform'])
                )
            )
            ->get();

        foreach ($conversations as $conversation) {
            $this->chatService->markConversationRead($conversation);
        }

        return response()->json(['success' => true]);
    }

    public function updates(Request $request, int $id): JsonResponse
    {
        $sinceId = (int) $request->query('since_id');
        $platform = $request->query('platform');
        $conversation = $this->chatService->resolveConversationByCustomer($id, $platform);

        if (!$conversation) {
            return response()->json([
                'messages' => [],
                'thread' => null,
                'has_updates' => false,
            ]);
        }

        try {
            $baseQuery = ChatMessage::query()
                ->with(['parent.attachments', 'attachments'])
                ->where('conversation_id', $conversation->id);

            $messages = $sinceId > 0
                ? (clone $baseQuery)->where('id', '>', $sinceId)->orderByRaw('COALESCE(sent_at, created_at) asc')->get()
                : (clone $baseQuery)->orderByRaw('COALESCE(sent_at, created_at) desc')->limit(50)->get()->reverse()->values();

            $normalized = $messages->map(fn (ChatMessage $message) => $this->formatMessage($message));
            $lastMessage = $messages->last();

            return response()->json([
                'messages' => $normalized,
                'thread' => $lastMessage ? [
                    'id' => (int) $id,
                    'last_message_text' => $conversation->last_message_preview,
                    'last_message_at' => optional($conversation->last_message_at)->toDateTimeString(),
                ] : null,
                'has_updates' => $messages->isNotEmpty(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Chat updates failed', [
                'customer_id' => $id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Error'], 500);
        }
    }

    public function sync(Request $request, int $id, MetaService $metaService): JsonResponse
    {
        $customer = Customer::find($id);
        if (!$customer) {
            return response()->json(['error' => 'Customer not found'], 404);
        }

        $platform = $request->query('platform')
            ?: $this->chatService->resolveConversationByCustomer($id)?->contact?->platform
            ?: ($customer->instagram_user_id ? 'instagram' : 'messenger');

        try {
            $count = $metaService->syncHistory($customer, $platform);
        } catch (\Throwable $e) {
            Log::error('Chat force sync failed', [
                'customer_id' => $id,
                'platform' => $platform,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['success' => false], 500);
        }

        return response()->json(['success' => true, 'count' => $count]);
    }

    public function getUnreadCount(): JsonResponse
    {
        $count = ChatConversation::query()
            ->where('unread_count', '>', 0)
            ->count();

        return response()->json(['count' => $count]);
    }

    public function refreshProfile(Request $request, int $id, MetaService $metaService): JsonResponse
    {
        $customer = Customer::find($id);
        if (!$customer) {
            return response()->json(['error' => 'Customer not found'], 404);
        }

        $platform = $request->query('platform');
        $conversation = $this->chatService->resolveConversationByCustomer($id, $platform);
        if (!$conversation) {
            $conversation = $this->ensureConversationForCustomer($customer, $platform, $metaService);
        }

        if (!$conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        $contact = $this->chatService->syncContactProfile($conversation->contact, $metaService, $customer);
        $conversation = $conversation->fresh(['contact', 'customer', 'stage', 'lastMessage.attachments']);

        return response()->json([
            'data' => [
                'id' => $customer->id,
                'first_name' => $customer->first_name ?: $contact->first_name,
                'last_name' => $customer->last_name ?: $contact->last_name,
                'fb_profile_pic' => $this->chatService->formatAvatarUrl($contact),
                'fb_user_id' => $contact->platform === 'messenger' ? $contact->external_user_id : $customer->fb_user_id,
                'instagram_user_id' => $contact->platform === 'instagram' ? $contact->external_user_id : $customer->instagram_user_id,
            ],
        ]);
    }

    private function availableStages(): array
    {
        return [
            'new',
            'waiting_reply',
            'order_confirmed',
            'done',
            'closed',
        ];
    }

    private function formatConversation(ChatConversation $conversation): array
    {
        $contact = $conversation->contact;
        $customer = $conversation->customer;
        $stageCode = $conversation->stage?->code;
        if ($stageCode === 'no_stage') {
            $stageCode = null;
        }

        $customerName = trim((string) ($customer?->full_name ?: ''));
        if ($customerName === '') {
            $customerName = $contact?->display_name ?: trim((string) (($contact?->first_name ?? '') . ' ' . ($contact?->last_name ?? '')));
        }
        if ($customerName === '') {
            $customerName = $contact?->external_username ?: 'Невідомий клієнт';
        }

        return [
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'customer_name' => $customerName,
            'customer_avatar' => $this->chatService->formatAvatarUrl($contact),
            'fb_profile_pic' => $this->chatService->formatAvatarUrl($contact),
            'first_name' => $customer?->first_name ?: $contact?->first_name,
            'last_name' => $customer?->last_name ?: $contact?->last_name,
            'phone' => $customer?->phone,
            'email' => $customer?->email,
            'last_message' => $conversation->last_message_preview,
            'last_message_time' => optional($conversation->last_message_at)->toDateTimeString(),
            'unread_count' => (int) $conversation->unread_count,
            'platform' => $contact?->platform,
            'status' => $conversation->status,
            'stage' => $stageCode,
            'tags' => [],
            'source' => $contact?->platform,
            'fb_user_id' => $contact?->platform === 'messenger'
                ? $contact->external_user_id
                : $customer?->fb_user_id,
            'instagram_user_id' => $contact?->platform === 'instagram'
                ? $contact->external_user_id
                : $customer?->instagram_user_id,
            'external_username' => $contact?->external_username,
        ];
    }

    private function formatMessage(ChatMessage $message): array
    {
        $parent = $message->parent;

        return [
            'id' => $message->id,
            'text' => $message->text ?? null,
            'direction' => $message->direction,
            'created_at' => ($message->sent_at ?? $message->created_at)?->toDateTimeString(),
            'attachments' => $message->attachments->map(fn ($attachment) => [
                'type' => $attachment->attachment_type,
                'url' => $attachment->public_url ?: ($attachment->storage_path ? 'chat/' . ltrim($attachment->storage_path, '/') : null),
            ])->filter(fn ($attachment) => !empty($attachment['url']))->values()->all(),
            'status' => $message->delivery_status,
            'is_read' => $message->read_at !== null || $message->delivery_status === 'read',
            'mid' => $message->external_message_id,
            'reply_to' => $parent ? [
                'text' => $parent->text ?? null,
                'direction' => $parent->direction,
                'attachments' => $parent->attachments->map(fn ($attachment) => [
                    'type' => $attachment->attachment_type,
                    'url' => $attachment->public_url ?: ($attachment->storage_path ? 'chat/' . ltrim($attachment->storage_path, '/') : null),
                ])->filter(fn ($attachment) => !empty($attachment['url']))->values()->all(),
            ] : null,
        ];
    }

    private function ensureConversationForCustomer(
        Customer $customer,
        ?string $platform,
        MetaService $metaService
    ): ?ChatConversation {
        $existingConversation = $this->chatService->resolveConversationByCustomer($customer->id, $platform);
        if ($existingConversation) {
            return $existingConversation;
        }

        $resolvedPlatform = $platform ?: ($customer->instagram_user_id ? 'instagram' : 'messenger');
        $externalUserId = $resolvedPlatform === 'instagram'
            ? $customer->instagram_user_id
            : $customer->fb_user_id;

        if (!$externalUserId) {
            return null;
        }

        try {
            $connection = $this->chatService->getCurrentConnection();
        } catch (\Throwable $e) {
            Log::error('Chat connection resolve failed', [
                'customer_id' => $customer->id,
                'platform' => $resolvedPlatform,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $contact = ChatContact::query()->firstOrCreate(
            [
                'meta_connection_id' => $connection->id,
                'platform' => $resolvedPlatform,
                'external_user_id' => $externalUserId,
            ],
            [
                'customer_id' => $customer->id,
                'display_name' => trim($customer->full_name) ?: $customer->instagram_username ?: null,
            ]
        );

        if (!$contact->customer_id) {
            $contact->customer_id = $customer->id;
            $contact->save();
        }

        if (!$contact->display_name || !$contact->avatar_path) {
            $contact = $this->chatService->syncContactProfile($contact, $metaService, $customer);
        }

        return $this->chatService->getOrCreateConversation($contact, $customer);
    }

    private function collectOutgoingAttachments(Request $request, array $remoteUrls): array
    {
        $attachments = [];
        $files = $request->file('files', []);
        if (!is_array($files)) {
            $files = [$files];
        }

        foreach ($files as $file) {
            $datePath = now()->format('Y/m/d');
            $destinationPath = public_path("chat/attachments/{$datePath}");
            if (!is_dir($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }

            $extension = $file->getClientOriginalExtension() ?: 'jpg';
            $fileName = time() . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($extension);
            $file->move($destinationPath, $fileName);
            @chmod($destinationPath . '/' . $fileName, 0644);

            $relativeUrl = "chat/attachments/{$datePath}/{$fileName}";
            $type = $this->inferFileAttachmentType($file->getMimeType(), $fileName);

            $attachments[] = [
                'meta_payload' => ['type' => $type, 'url' => url($relativeUrl)],
                'stored_attachment' => [
                    'type' => $type,
                    'url' => $relativeUrl,
                    'mime_type' => $file->getMimeType(),
                    'file_name' => $file->getClientOriginalName(),
                    'file_size' => $file->getSize(),
                ],
            ];
        }

        foreach ($remoteUrls as $remoteUrl) {
            $remoteUrl = trim((string) $remoteUrl);
            if ($remoteUrl === '') {
                continue;
            }

            $type = $this->inferRemoteAttachmentType($remoteUrl);
            $attachments[] = [
                'meta_payload' => [
                    'type' => $type,
                    'url' => str_starts_with($remoteUrl, 'http') ? $remoteUrl : url(ltrim($remoteUrl, '/')),
                ],
                'stored_attachment' => [
                    'type' => $type,
                    'url' => $remoteUrl,
                ],
            ];
        }

        return $attachments;
    }

    private function inferRemoteAttachmentType(string $url): string
    {
        $lower = strtolower(parse_url($url, PHP_URL_PATH) ?? $url);
        if (preg_match('/\.(mp4|mov|webm)$/i', $lower)) {
            return 'video';
        }
        if (preg_match('/\.(mp3|wav|ogg)$/i', $lower)) {
            return 'audio';
        }
        if (preg_match('/\.(pdf|doc|docx|xls|xlsx)$/i', $lower)) {
            return 'file';
        }

        return 'image';
    }

    private function inferFileAttachmentType(?string $mimeType, string $fileName): string
    {
        $mimeType = strtolower((string) $mimeType);
        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }
        if (str_starts_with($mimeType, 'audio/')) {
            return 'audio';
        }

        return $this->inferRemoteAttachmentType($fileName);
    }
}
