<?php

namespace App\Http\Controllers;

use App\Models\ChatAiConversationState;
use App\Models\ChatAiEvent;
use App\Models\ChatAiRun;
use App\Models\ChatContact;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatMessageAttachment;
use App\Models\ChatStage;
use App\Models\Customer;
use App\Services\ChatAiSettingsService;
use App\Services\ChatService;
use App\Services\MetaService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ChatApiController extends Controller
{
    public function __construct(
        private readonly ChatService $chatService,
        private readonly ChatAiSettingsService $chatAiSettingsService,
    ) {
    }

    private ?array $aiDefaultsCache = null;

    public function list(): JsonResponse
    {
        try {
            $conversations = ChatConversation::query()
                ->where('status', '!=', 'archived')
                ->with([
                    'contact',
                    'customer',
                    'stage',
                    'assignedUser',
                    'aiState',
                    'aiState.selectedProduct:id,title',
                    'aiState.selectedVariant:id,size',
                    'aiState.selectedColor:id,name',
                ])
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
            ->with([
                'contact',
                'customer',
                'stage',
                'assignedUser',
                'aiState',
                'aiState.selectedProduct:id,title',
                'aiState.selectedVariant:id,size',
                'aiState.selectedColor:id,name',
            ])
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

    public function updateAi(Request $request, ChatConversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $aiMeta = is_array($meta['ai'] ?? null) ? $meta['ai'] : [];
        $aiMeta['enabled'] = (bool) $validated['enabled'];
        $aiMeta['updated_at'] = now()->toDateTimeString();
        $meta['ai'] = $aiMeta;

        $conversation->meta = $meta;
        $conversation->save();
        $conversation = $conversation->fresh([
            'contact',
            'customer',
            'stage',
            'assignedUser',
            'lastMessage.attachments',
            'aiState',
            'aiState.selectedProduct:id,title',
            'aiState.selectedVariant:id,size',
            'aiState.selectedColor:id,name',
        ]);

        return response()->json([
            'data' => $this->formatConversation($conversation),
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

    public function clearConversationHistory(ChatConversation $conversation): JsonResponse
    {
        try {
            $filesToDelete = [];

            DB::transaction(function () use ($conversation, &$filesToDelete): void {
                $messageIdSubQuery = ChatMessage::query()
                    ->select('id')
                    ->where('conversation_id', $conversation->id);

                $filesToDelete = ChatMessageAttachment::query()
                    ->whereIn('message_id', $messageIdSubQuery)
                    ->whereNotNull('storage_path')
                    ->get(['storage_disk', 'storage_path'])
                    ->map(static fn (ChatMessageAttachment $attachment) => [
                        'disk' => (string) ($attachment->storage_disk ?: 'chat_uploads'),
                        'path' => (string) $attachment->storage_path,
                    ])
                    ->filter(static fn (array $file) => trim($file['path']) !== '')
                    ->values()
                    ->all();

                ChatMessage::query()
                    ->where('conversation_id', $conversation->id)
                    ->delete();

                // Очищаємо AI-контекст діалогу, щоб після reset не лишалися попередні поля підбору.
                ChatAiEvent::query()
                    ->where('conversation_id', $conversation->id)
                    ->delete();

                ChatAiRun::query()
                    ->where('conversation_id', $conversation->id)
                    ->delete();

                ChatAiConversationState::query()
                    ->where('conversation_id', $conversation->id)
                    ->delete();

                $conversation->last_message_id = null;
                $conversation->last_message_preview = null;
                $conversation->last_message_at = null;
                $conversation->last_inbound_at = null;
                $conversation->last_outbound_at = null;
                $conversation->unread_count = 0;
                $conversation->status = 'open';
                $conversation->closed_at = null;
                $conversation->meta = $this->resetConversationMetaAfterHistoryClear($conversation);
                $conversation->save();
            });

            foreach ($filesToDelete as $file) {
                $this->deleteAttachmentFile($file['disk'], $file['path']);
            }

            $freshConversation = $conversation->fresh([
                'contact',
                'customer',
                'stage',
                'assignedUser',
                'lastMessage.attachments',
                'aiState',
                'aiState.selectedProduct:id,title',
                'aiState.selectedVariant:id,size',
                'aiState.selectedColor:id,name',
            ]);

            return response()->json([
                'success' => true,
                'conversation' => $freshConversation
                    ? $this->formatConversation($freshConversation)
                    : null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Chat clear history failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Не вдалося очистити історію чату.'], 500);
        }
    }

    private function resetConversationMetaAfterHistoryClear(ChatConversation $conversation): array
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $meta['history_cleared_at'] = now()->toDateTimeString();
        $aiMeta = is_array($meta['ai'] ?? null) ? $meta['ai'] : null;
        if ($aiMeta === null) {
            unset($meta['ai']);

            return $meta;
        }

        // Залишаємо тільки налаштування доступності, а runtime-дані AI повністю скидаємо.
        $nextAiMeta = [];
        if (array_key_exists('enabled', $aiMeta)) {
            $nextAiMeta['enabled'] = (bool) $aiMeta['enabled'];
        }
        if (is_string($aiMeta['agent_code'] ?? null) && trim($aiMeta['agent_code']) !== '') {
            $nextAiMeta['agent_code'] = trim((string) $aiMeta['agent_code']);
        }

        if ($nextAiMeta === []) {
            unset($meta['ai']);
        } else {
            $nextAiMeta['updated_at'] = now()->toDateTimeString();
            $meta['ai'] = $nextAiMeta;
        }

        return $meta;
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

            $historyClearedAt = data_get($conversation->meta, 'history_cleared_at');
            $skipAutoSync = is_string($historyClearedAt) && trim($historyClearedAt) !== '';

            // Після ручного очищення історії не підтягуємо Meta-архів автоматично.
            if (!$hasMessages && !$skipAutoSync) {
                $metaService->syncHistory($customer, $conversation->contact->platform);
                $conversation = $conversation->fresh(['contact', 'customer', 'stage', 'lastMessage.attachments']);
            }

            if (!data_get($conversation->meta, 'origin_context')) {
                $originCandidate = ChatMessage::query()
                    ->where('conversation_id', $conversation->id)
                    ->whereNotNull('text')
                    ->orderBy('id')
                    ->get(['id', 'text'])
                    ->first(function (ChatMessage $message) use ($conversation) {
                        return (bool) $this->chatService->extractOriginContext(
                            $message->text,
                            $conversation->contact?->platform
                        );
                    });

                if ($originCandidate) {
                    $originContext = $this->chatService->extractOriginContext(
                        $originCandidate->text,
                        $conversation->contact?->platform
                    );

                    if ($originContext) {
                        $originContext = $this->chatService->ensureOriginPreview($originContext);
                        $this->chatService->syncConversationOrigin($conversation, $originContext);
                        $originCandidateModel = ChatMessage::query()->find($originCandidate->id);
                        if ($originCandidateModel) {
                            $this->chatService->syncMessageOrigin($originCandidateModel, $originContext);
                        }
                        $conversation = $conversation->fresh(['contact', 'customer', 'stage', 'lastMessage.attachments']);
                    }
                }
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

            $conversation = $conversation->fresh(['contact', 'customer', 'stage', 'assignedUser', 'lastMessage.attachments']);

            return response()->json([
                'data' => $createdMessages,
                'conversation' => $this->formatConversation($conversation),
            ]);
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
            $conversation = $conversation->fresh(['contact', 'customer', 'stage', 'assignedUser', 'lastMessage.attachments']);

            return response()->json([
                'messages' => $normalized,
                'thread' => $lastMessage ? [
                    'id' => (int) $id,
                    'last_message_text' => $conversation->last_message_preview,
                    'last_message_at' => optional($conversation->last_message_at)->toDateTimeString(),
                ] : null,
                'conversation' => $this->formatConversation($conversation),
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

        $conversation = $this->chatService->hydrateConversationProfile($conversation, $metaService, true);
        $conversation = $conversation->fresh([
            'contact',
            'customer',
            'stage',
            'assignedUser',
            'lastMessage.attachments',
            'aiState',
            'aiState.selectedProduct:id,title',
            'aiState.selectedVariant:id,size',
            'aiState.selectedColor:id,name',
        ]);

        return response()->json(['data' => $this->formatConversation($conversation)]);
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
        // У списку чатів працюємо тільки по вже збережених даних, без повільного recovery/refresh профілю.
        $profileSnapshot = $this->chatService->buildConversationProfileSnapshot($contact, $customer, false);
        $originContext = $this->resolveOriginContext(
            data_get($conversation->meta, 'origin_context'),
            $conversation->last_message_preview,
            $contact?->platform
        );
        if ($originContext && data_get($conversation->meta, 'origin_context') !== $originContext) {
            $this->chatService->syncConversationOrigin($conversation, $originContext);
        }
        $originType = $originContext['object_type'] ?? ($originContext ? 'comment' : 'direct');
        $originPlatform = $originContext['platform'] ?? $contact?->platform;
        $stageCode = $conversation->stage?->code;
        if ($stageCode === 'no_stage') {
            $stageCode = null;
        }

        $aiDefaults = $this->getAiDefaults();
        $aiMeta = is_array(data_get($conversation->meta, 'ai')) ? data_get($conversation->meta, 'ai') : [];
        $aiEnabled = array_key_exists('enabled', $aiMeta)
            ? (bool) $aiMeta['enabled']
            : true;
        $aiStage = is_string($aiMeta['stage'] ?? null) ? $aiMeta['stage'] : null;
        $aiState = $conversation->aiState;
        $slots = is_array($aiState?->slots_json) ? $aiState->slots_json : [];
        $delivery = is_array($slots['delivery'] ?? null) ? $slots['delivery'] : [];
        $selectedSize = $aiState?->selected_size ?: ($aiState?->selectedVariant?->size ?? null);
        $selectedModelTitle = $aiState?->selectedProduct?->title
            ? (string) $aiState->selectedProduct->title
            : null;
        $selectedColorName = $aiState?->selectedColor?->name
            ? (string) $aiState->selectedColor->name
            : null;
        $intentPurchase = (bool) ($aiState?->intent_purchase ?? false);
        $missingSlots = is_array($aiState?->missing_slots_json)
            ? array_values($aiState->missing_slots_json)
            : [];

        $cartItems = $this->normalizeAiCartItems(
            is_array($slots['cart_items'] ?? null) ? $slots['cart_items'] : [],
            $selectedModelTitle,
            $selectedColorName,
            $selectedSize
        );
        $cartPairsCount = 0;
        $cartTotalAmount = 0.0;
        foreach ($cartItems as $item) {
            $cartPairsCount += (int) ($item['qty'] ?? 0);
            $cartTotalAmount += (float) ($item['line_total'] ?? 0);
        }
        $cartPositionCount = count($cartItems);

        $summaryParts = [];
        if ($cartItems !== []) {
            foreach ($cartItems as $item) {
                $rowParts = [];
                if (is_string($item['model'] ?? null) && trim((string) $item['model']) !== '') {
                    $rowParts[] = trim((string) $item['model']);
                }
                if (is_string($item['color'] ?? null) && trim((string) $item['color']) !== '') {
                    $rowParts[] = trim((string) $item['color']);
                }
                if (is_string($item['size'] ?? null) && trim((string) $item['size']) !== '') {
                    $rowParts[] = trim((string) $item['size']);
                }
                if ($rowParts !== []) {
                    $summaryParts[] = implode(', ', $rowParts);
                }
            }
        } else {
            if ($selectedModelTitle) {
                $summaryParts[] = $selectedModelTitle;
            }
            if ($selectedColorName) {
                $summaryParts[] = $selectedColorName;
            }
            if ($selectedSize) {
                $summaryParts[] = (string) $selectedSize;
            }
        }

        $stageOrder = $this->resolveAiStageOrder($aiStage);
        $dialogStatus = $this->resolveAiDialogStatus(
            $aiStage,
            $intentPurchase,
            $missingSlots,
            $cartPositionCount,
            $delivery
        );
        $managerNote = $this->buildAiManagerNote(
            $intentPurchase,
            $cartItems,
            $missingSlots
        );

        return [
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'customer_name' => $profileSnapshot['display_name'],
            'customer_avatar' => $profileSnapshot['avatar_url'],
            'fb_profile_pic' => $profileSnapshot['avatar_url'],
            'first_name' => $profileSnapshot['first_name'],
            'last_name' => $profileSnapshot['last_name'],
            'phone' => $customer?->phone,
            'email' => $customer?->email,
            'last_message' => $conversation->last_message_preview,
            'last_message_time' => optional($conversation->last_message_at)->toDateTimeString(),
            'unread_count' => (int) $conversation->unread_count,
            'platform' => $contact?->platform,
            'status' => $conversation->status,
            'stage' => $stageCode,
            'thread_kind' => $originContext ? ($originContext['kind'] ?? 'direct') : 'direct',
            'origin_type' => $originType,
            'origin_platform' => $originPlatform,
            'origin_context' => $originContext,
            'tags' => [],
            'source' => $contact?->platform,
            'fb_user_id' => $contact?->platform === 'messenger'
                ? $contact->external_user_id
                : $customer?->fb_user_id,
            'instagram_user_id' => $contact?->platform === 'instagram'
                ? $contact->external_user_id
                : $customer?->instagram_user_id,
            'external_username' => $contact?->external_username,
            'assigned_user' => $conversation->assignedUser ? [
                'id' => $conversation->assignedUser->id,
                'name' => $conversation->assignedUser->name,
            ] : null,
            'ai' => [
                'enabled' => $aiEnabled,
                'stage' => $aiStage,
                'stage_order' => $stageOrder,
                'stage_label' => $this->resolveAiStageLabel($aiStage),
                'stage_badge' => $this->resolveAiStageBadge($aiStage),
                'agent_code' => is_string($aiMeta['agent_code'] ?? null) ? $aiMeta['agent_code'] : $aiDefaults['default_agent_code'],
                'last_run_id' => is_numeric($aiMeta['last_run_id'] ?? null) ? (int) $aiMeta['last_run_id'] : null,
                'updated_at' => is_string($aiMeta['updated_at'] ?? null) ? $aiMeta['updated_at'] : null,
                'reply_delay_seconds' => (int) $aiDefaults['reply_delay_seconds'],
                'collected' => [
                    'product' => $aiState?->selectedProduct ? [
                        'id' => $aiState->selectedProduct->id,
                        'title' => $aiState->selectedProduct->title,
                    ] : null,
                    'variant' => $aiState?->selectedVariant ? [
                        'id' => $aiState->selectedVariant->id,
                        'size' => $aiState->selectedVariant->size,
                    ] : null,
                    'color' => $aiState?->selectedColor ? [
                        'id' => $aiState->selectedColor->id,
                        'name' => $aiState->selectedColor->name,
                    ] : null,
                    'size' => $selectedSize,
                    'intent_purchase' => $intentPurchase,
                    'delivery' => [
                        'name' => is_string($delivery['name'] ?? null) ? $delivery['name'] : null,
                        'phone' => is_string($delivery['phone'] ?? null) ? $delivery['phone'] : null,
                        'city' => is_string($delivery['city'] ?? null) ? $delivery['city'] : null,
                        'warehouse' => is_string($delivery['warehouse'] ?? null) ? $delivery['warehouse'] : null,
                    ],
                    'missing_slots' => $missingSlots,
                    'cart_items' => $cartItems,
                    'cart' => [
                        'positions' => $cartPositionCount,
                        'pairs' => $cartPairsCount,
                        'amount' => round($cartTotalAmount, 2),
                    ],
                    'manager_note' => $managerNote,
                    'dialog_status' => $dialogStatus,
                    'summary' => $summaryParts !== [] ? implode(', ', $summaryParts) : null,
                ],
            ],
        ];
    }

    private function resolveAiStageOrder(?string $stage): int
    {
        return match ($stage) {
            'interest' => 1,
            'selection' => 2,
            'checkout_ready' => 3,
            'checkout' => 4,
            default => 1,
        };
    }

    private function resolveAiStageLabel(?string $stage): string
    {
        return match ($stage) {
            'interest' => 'Зацікавлення',
            'selection' => 'Підбір',
            'checkout_ready' => 'Готовність до оформлення',
            'checkout' => 'Оформлення',
            default => 'Зацікавлення',
        };
    }

    private function resolveAiStageBadge(?string $stage): string
    {
        return match ($stage) {
            'interest' => 'Консультація',
            'selection' => 'Підбір',
            'checkout_ready' => 'Перед оформленням',
            'checkout' => 'Оформлення',
            default => 'Консультація',
        };
    }

    /**
     * @param  array<int, mixed>  $rawCartItems
     * @return array<int, array{
     *   model:?string,
     *   color:?string,
     *   size:?string,
     *   price:?float,
     *   qty:int,
     *   line_total:?float,
     *   product_id:?int,
     *   variant_id:?int
     * }>
     */
    private function normalizeAiCartItems(
        array $rawCartItems,
        ?string $fallbackModel,
        ?string $fallbackColor,
        ?string $fallbackSize
    ): array {
        $items = [];
        foreach ($rawCartItems as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }

            $normalized = $this->normalizeAiCartItem($rawItem);
            if ($normalized !== null) {
                $items[] = $normalized;
            }
        }

        // Підтримуємо старий single-item стан, якщо кошик ще не заповнений.
        if ($items === []) {
            $hasFallbackData = trim((string) $fallbackModel) !== ''
                || trim((string) $fallbackColor) !== ''
                || trim((string) $fallbackSize) !== '';

            if ($hasFallbackData) {
                $items[] = [
                    'model' => trim((string) $fallbackModel) !== '' ? trim((string) $fallbackModel) : null,
                    'color' => trim((string) $fallbackColor) !== '' ? trim((string) $fallbackColor) : null,
                    'size' => trim((string) $fallbackSize) !== '' ? trim((string) $fallbackSize) : null,
                    'price' => null,
                    'qty' => 1,
                    'line_total' => null,
                    'product_id' => null,
                    'variant_id' => null,
                ];
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{
     *   model:?string,
     *   color:?string,
     *   size:?string,
     *   price:?float,
     *   qty:int,
     *   line_total:?float,
     *   product_id:?int,
     *   variant_id:?int
     * }|null
     */
    private function normalizeAiCartItem(array $item): ?array
    {
        $model = is_scalar($item['model'] ?? null) ? trim((string) $item['model']) : '';
        $color = is_scalar($item['color'] ?? null) ? trim((string) $item['color']) : '';
        $size = is_scalar($item['size'] ?? null) ? trim((string) $item['size']) : '';
        $qty = max(1, (int) ($item['qty'] ?? 1));
        $price = is_numeric($item['price'] ?? null) ? (float) $item['price'] : null;
        $lineTotal = is_numeric($item['line_total'] ?? null)
            ? (float) $item['line_total']
            : ($price !== null ? round($price * $qty, 2) : null);
        $productId = is_numeric($item['product_id'] ?? null) ? (int) $item['product_id'] : null;
        $variantId = is_numeric($item['variant_id'] ?? null) ? (int) $item['variant_id'] : null;

        if (
            $model === ''
            && $color === ''
            && $size === ''
            && $price === null
            && $productId === null
            && $variantId === null
        ) {
            return null;
        }

        return [
            'model' => $model !== '' ? $model : null,
            'color' => $color !== '' ? $color : null,
            'size' => $size !== '' ? $size : null,
            'price' => $price,
            'qty' => $qty,
            'line_total' => $lineTotal,
            'product_id' => $productId,
            'variant_id' => $variantId,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $cartItems
     * @param  array<int, string>  $missingSlots
     */
    private function buildAiManagerNote(
        bool $intentPurchase,
        array $cartItems,
        array $missingSlots
    ): string {
        if ($cartItems === []) {
            return 'Клієнт ще формує вибір. Поки немає підтверджених позицій у кошику.';
        }

        $lines = [];
        $lines[] = $intentPurchase
            ? 'Клієнт підтвердив замовлення.'
            : 'Клієнт сформував позиції у кошику, очікуємо підтвердження.';

        $positionParts = [];
        foreach ($cartItems as $item) {
            $color = is_scalar($item['color'] ?? null) && trim((string) $item['color']) !== ''
                ? mb_strtolower(trim((string) $item['color']))
                : 'без кольору';
            $size = is_scalar($item['size'] ?? null) && trim((string) $item['size']) !== ''
                ? trim((string) $item['size'])
                : 'без розміру';
            $qty = max(1, (int) ($item['qty'] ?? 1));
            $positionParts[] = $color . ' ' . $size . ' (' . $qty . ')';
        }
        if ($positionParts !== []) {
            $lines[] = 'Позиції: ' . implode(', ', $positionParts) . '.';
        }

        $deliveryMissing = [];
        $deliveryMap = [
            'name' => 'імʼя та прізвище',
            'phone' => 'телефон',
            'city' => 'місто',
            'warehouse' => 'відділення/поштомат',
        ];
        foreach ($deliveryMap as $key => $label) {
            if (in_array($key, $missingSlots, true)) {
                $deliveryMissing[] = $label;
            }
        }

        if ($deliveryMissing !== []) {
            $lines[] = 'Потрібно дозібрати: ' . implode(', ', $deliveryMissing) . '.';
        } else {
            $lines[] = 'Дані доставки зібрано.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, string>  $missingSlots
     */
    private function resolveAiDialogStatus(
        ?string $stage,
        bool $intentPurchase,
        array $missingSlots,
        int $cartPositionCount,
        array $delivery
    ): string {
        $hasDelivery = true;
        foreach (['name', 'phone', 'city', 'warehouse'] as $field) {
            if (!is_string($delivery[$field] ?? null) || trim((string) $delivery[$field]) === '') {
                $hasDelivery = false;
                break;
            }
        }

        if ($hasDelivery && $intentPurchase) {
            return 'Оформлення завершено';
        }

        if ($intentPurchase && $cartPositionCount > 0) {
            return 'Підтверджено клієнтом';
        }

        if ($stage === 'checkout_ready') {
            return 'Готовий до оформлення';
        }

        if ($stage === 'checkout') {
            return 'Оформлення';
        }

        if ($stage === 'selection' || $cartPositionCount > 0) {
            return 'Підбір позицій';
        }

        if (in_array('purchase_intent', $missingSlots, true)) {
            return 'Консультація';
        }

        return 'Активний діалог';
    }

    private function getAiDefaults(): array
    {
        if ($this->aiDefaultsCache === null) {
            $this->aiDefaultsCache = $this->chatAiSettingsService->get();
        }

        return $this->aiDefaultsCache;
    }

    private function formatMessage(ChatMessage $message): array
    {
        $parent = $message->parent;
        $originContext = $this->resolveOriginContext(
            data_get($message->meta, 'origin_context'),
            $message->text,
            $message->conversation?->contact?->platform
        );
        if ($originContext && data_get($message->meta, 'origin_context') !== $originContext) {
            $this->chatService->syncMessageOrigin($message, $originContext);
        }
        $displayText = $this->formatMessageText($message->text, $originContext);

        return [
            'id' => $message->id,
            'text' => $displayText,
            'raw_text' => $message->text ?? null,
            'direction' => $message->direction,
            'created_at' => ($message->sent_at ?? $message->created_at)?->toDateTimeString(),
            'attachments' => $message->attachments->map(fn ($attachment) => [
                'type' => $attachment->attachment_type,
                'url' => $attachment->public_url ?: ($attachment->storage_path ? 'chat/' . ltrim($attachment->storage_path, '/') : null),
            ])->filter(fn ($attachment) => !empty($attachment['url']))->values()->all(),
            'status' => $message->delivery_status,
            'is_read' => $message->read_at !== null || $message->delivery_status === 'read',
            'mid' => $message->external_message_id,
            'origin_context' => $originContext,
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

    private function formatMessageText(?string $text, ?array $originContext): ?string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }

        if (!$originContext) {
            return $text;
        }

        $cleaned = preg_replace('~https?://[^\s)]+~u', '', $text);
        $cleaned = preg_replace('/\(\s*\)$/u', '', (string) $cleaned);
        $cleaned = trim(preg_replace('/\s+/u', ' ', (string) $cleaned));

        $lower = mb_strtolower($cleaned);
        if (
            $cleaned === ''
            || str_contains($lower, 'ви відповідаєте на коментар')
            || str_contains($lower, 'вы отвечаете на комментарий')
            || str_contains($lower, 'you are replying to a comment')
            || str_contains($lower, 'посмотреть комментарий')
            || str_contains($lower, 'view comment')
        ) {
            return null;
        }

        return $cleaned;
    }

    private function resolveOriginContext(?array $storedOriginContext, ?string $text, ?string $platform): ?array
    {
        $derivedOriginContext = $this->chatService->extractOriginContext($text, $platform);

        if (!$storedOriginContext) {
            return $derivedOriginContext;
        }

        if (!$derivedOriginContext) {
            return $this->chatService->ensureOriginPreview($storedOriginContext);
        }

        return $this->chatService->ensureOriginPreview(array_merge($derivedOriginContext, $storedOriginContext));
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
                'display_name' => trim($customer->full_name) !== ''
                    && !str_contains((string) $customer->full_name, 'User')
                    ? trim($customer->full_name)
                    : ($customer->instagram_username ?: null),
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'external_username' => $customer->instagram_username,
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
            $originalName = (string) $file->getClientOriginalName();
            $fileSize = $file->getSize();
            $mimeType = null;

            try {
                $mimeType = $file->getMimeType() ?: $file->getClientMimeType();
            } catch (\Throwable $e) {
                $mimeType = $file->getClientMimeType();
                Log::warning('Не вдалося визначити MIME завантаженого файла до переміщення', [
                    'name' => $originalName,
                    'error' => $e->getMessage(),
                ]);
            }

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
            $type = $this->inferFileAttachmentType($mimeType, $fileName);

            $attachments[] = [
                'meta_payload' => ['type' => $type, 'url' => url($relativeUrl)],
                'stored_attachment' => [
                    'type' => $type,
                    'url' => $relativeUrl,
                    'mime_type' => $mimeType,
                    'file_name' => $originalName,
                    'file_size' => $fileSize,
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

    private function deleteAttachmentFile(string $disk, string $path): void
    {
        $normalizedPath = ltrim($path, '/');
        if ($normalizedPath === '') {
            return;
        }

        try {
            if (Storage::disk($disk)->exists($normalizedPath)) {
                Storage::disk($disk)->delete($normalizedPath);
            }
        } catch (\Throwable $e) {
            Log::warning('Не вдалося видалити вкладення з disk', [
                'disk' => $disk,
                'path' => $normalizedPath,
                'error' => $e->getMessage(),
            ]);
        }

        $publicAbsolutePath = public_path('chat/' . $normalizedPath);
        if (is_file($publicAbsolutePath)) {
            @unlink($publicAbsolutePath);
        }
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
