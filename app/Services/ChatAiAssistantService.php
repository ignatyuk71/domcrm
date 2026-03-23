<?php

namespace App\Services;

use App\Models\ChatAiResponseRule;
use App\Models\ChatAiTopic;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChatAiAssistantService
{
    /**
     * @var array<string, string>|null
     */
    private ?array $cachedColorStemMap = null;

    public function __construct(
        private readonly ChatAiSettingsService $settingsService,
        private readonly OpenAiResponsesService $openAi,
        private readonly ChatService $chatService,
        private readonly MetaService $metaService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function processInboundMessage(int $messageId): array
    {
        $message = ChatMessage::query()
            ->with([
                'conversation.contact',
                'conversation.customer',
            ])
            ->find($messageId);

        if (!$message) {
            return ['status' => 'skip', 'reason' => 'message_not_found'];
        }

        $skipReason = $this->resolveSkipReason($message);
        if ($skipReason !== null) {
            $this->markMessageAiState($message, [
                'status' => 'skipped',
                'reason' => $skipReason,
            ]);
            if ($message->conversation) {
                $this->setConversationAiStatus($message->conversation, [
                    'status_code' => "skip_{$skipReason}",
                    'status_note' => $this->buildSkipStatusNote($skipReason),
                ]);
            }

            return ['status' => 'skip', 'reason' => $skipReason];
        }

        $conversation = $message->conversation;
        $settings = $this->settingsService->resolveRuntimeSettings();
        $rules = $this->loadActiveRules();
        $topics = $this->loadActiveTopics();

        $normalizedMessageText = $this->normalizeText((string) ($message->text ?? ''));
        $requestedSize = $this->extractRequestedSize($message->text);
        $topicMatch = $this->resolveTopicMatch(
            $topics,
            (string) ($message->text ?? ''),
            $conversation,
            $settings
        );
        $topic = $topicMatch['topic'];
        $topicScore = (int) ($topicMatch['score'] ?? 0);
        $isTopicUnclear = $topic === null;

        $products = $topic ? $this->resolveTopicProducts($topic, $requestedSize) : collect();
        $mediaCandidates = $topic ? $this->resolveTopicMedia($topic, $products) : collect();
        $slotState = $this->buildConversationSlotState(
            $conversation,
            $message,
            $settings,
            $topic,
            $products,
            $topicScore,
            $requestedSize
        );
        $orderDraft = $this->buildConversationOrderDraft(
            $conversation,
            $message,
            $topic,
            $products,
            $slotState,
            $settings
        );

        $awaitingPhotoConfirmation = $this->isAwaitingPhotoConfirmation($conversation);
        $explicitPhotoRequest = $this->isPhotoRequest((string) ($message->text ?? ''));
        $confirmedPhotoRequest = $awaitingPhotoConfirmation && $this->isAffirmativeReply((string) ($message->text ?? ''));
        $declinedPhotoRequest = $awaitingPhotoConfirmation && $this->isNegativeReply((string) ($message->text ?? ''));
        $ambiguousVisualIntent = !$explicitPhotoRequest
            && !$confirmedPhotoRequest
            && !$declinedPhotoRequest
            && $this->isAmbiguousVisualIntent((string) ($message->text ?? ''));
        $hasResolvedVisualContext = $this->hasResolvedVisualContext($topic, $slotState, $conversation);
        $hasSelectedModelContext = $this->hasSelectedModelContext($topic, $slotState, $conversation);
        $allTopicsOverviewMedia = $this->resolveAllTopicsOverviewMedia($topics);
        $mediaIntent = $this->resolveMediaIntent(
            $topics,
            $topic,
            $products,
            $slotState,
            $conversation,
            (string) ($message->text ?? ''),
            $settings,
            $allTopicsOverviewMedia,
            $explicitPhotoRequest,
            $ambiguousVisualIntent,
            $confirmedPhotoRequest,
            $declinedPhotoRequest,
            $hasResolvedVisualContext
        );
        $forceOverviewForModelSelection = $this->shouldSendTopicOverviewForModelSelection(
            $normalizedMessageText,
            $requestedSize,
            $slotState,
            $allTopicsOverviewMedia
        );
        if ($forceOverviewForModelSelection && (($mediaIntent['intent'] ?? 'none') !== 'show_models')) {
            $mediaIntent = [
                'intent' => 'show_models',
                'source' => 'forced_model_selection',
                'reason' => 'Модель ще не визначена, система примусово надсилає всі колажі моделей.',
                'confidence' => null,
                'target_color' => null,
            ];
        }
        $mediaIntentName = (string) ($mediaIntent['intent'] ?? 'none');
        $mediaIntentColor = $this->normalizeSlotValue('color', $mediaIntent['target_color'] ?? null);
        $currentModelGalleryRequest = $mediaIntentName === 'show_all_current_model_photos';
        $specificModelPhotoRequest = $mediaIntentName === 'show_specific_color_photo';
        $isPhotoRequest = $confirmedPhotoRequest || $specificModelPhotoRequest || $currentModelGalleryRequest;
        $isAllPhotosRequest = $currentModelGalleryRequest;
        $shouldSendOverviewMedia = $allTopicsOverviewMedia->isNotEmpty()
            && ($forceOverviewForModelSelection || $mediaIntentName === 'show_models');
        $mediaSelectionQuery = $this->buildMediaSelectionQuery(
            (string) ($message->text ?? ''),
            $slotState,
            $topic,
            $isPhotoRequest,
            $specificModelPhotoRequest ? $mediaIntentColor : null
        );
        $previewMedia = $mediaCandidates->isNotEmpty()
            ? $this->selectMediaForReply($mediaCandidates, $mediaSelectionQuery, false)
            : collect();
        $shouldAskPhotoConfirmation = $mediaIntentName === 'confirm_photo'
            && $hasResolvedVisualContext
            && $previewMedia->isNotEmpty();

        $selectedMedia = $shouldSendOverviewMedia
            ? $allTopicsOverviewMedia
            : ($isPhotoRequest
                ? $this->selectMediaForReply($mediaCandidates, $mediaSelectionQuery, $isAllPhotosRequest)
                : collect());
        $mediaForPrompt = $shouldAskPhotoConfirmation ? $previewMedia : $selectedMedia;

        if ($slotState['just_completed'] && $slotState['order_ready']) {
            $reply = [
                'reply_text' => $this->buildOrderReadyReply($slotState),
                'handoff' => true,
                'handoff_reason' => 'Зібрано всі дані для оформлення замовлення.',
            ];
        } else {
            try {
                $reply = $this->buildReply(
                    $message,
                    $settings,
                    $rules,
                    $topics,
                    $topic,
                    $products,
                    $mediaForPrompt,
                    $slotState,
                    $requestedSize,
                    $isPhotoRequest,
                    $isAllPhotosRequest,
                    $shouldAskPhotoConfirmation,
                    $shouldSendOverviewMedia
                );
            } catch (\Throwable $e) {
                Log::warning('AI: помилка генерації відповіді', [
                    'conversation_id' => $conversation?->id,
                    'message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);

                $this->markMessageAiState($message, [
                    'status' => 'error',
                    'reason' => 'openai_failed',
                    'error' => Str::limit($e->getMessage(), 250),
                ]);
                $this->setConversationAiStatus($conversation, [
                    'status_code' => 'openai_failed',
                    'status_note' => 'AI не зміг сформувати відповідь. Потрібна перевірка менеджером.',
                    'last_error' => Str::limit($e->getMessage(), 250),
                ]);

                return ['status' => 'error', 'reason' => 'openai_failed'];
            }
        }

        $sentMediaCount = 0;
        if (($isPhotoRequest || $shouldSendOverviewMedia) && $selectedMedia->isNotEmpty()) {
            $sentMediaCount = $this->sendMediaMessages($conversation, $selectedMedia);
        }

        $sentText = false;
        $replyText = trim((string) ($reply['reply_text'] ?? ''));
        if ($replyText !== '') {
            $sentText = $this->sendTextMessage($conversation, $replyText);
        }

        $handoff = (bool) ($reply['handoff'] ?? false);
        $handoffReason = trim((string) ($reply['handoff_reason'] ?? ''));

        $conversationAiContext = [
            'status_code' => $isTopicUnclear ? 'topic_overview' : 'replied',
            'status_note' => $isTopicUnclear
                ? $this->buildUnknownTopicStatusNote($sentMediaCount)
                : $this->buildRuntimeStatusNote(
                    $topic,
                    $requestedSize,
                    $isPhotoRequest,
                    $sentMediaCount
                ),
            'last_error' => null,
            'last_requested_size' => $requestedSize,
            'last_photo_request' => $isPhotoRequest,
            'last_all_photo_request' => $isAllPhotosRequest,
            'awaiting_photo_confirmation' => $shouldAskPhotoConfirmation,
            'topic_unresolved' => $isTopicUnclear,
            'topic_route_source' => $topicMatch['route_source'] ?? null,
            'topic_route_reason' => $topicMatch['route_reason'] ?? null,
            'topic_route_confidence' => $topicMatch['route_confidence'] ?? null,
            'media_intent' => $mediaIntentName,
            'media_intent_source' => $mediaIntent['source'] ?? null,
            'media_intent_reason' => $mediaIntent['reason'] ?? null,
            'media_intent_confidence' => $mediaIntent['confidence'] ?? null,
            'media_intent_target_color' => $mediaIntentColor,
            'order_intent' => $slotState['order_intent'] ?? null,
            'order_intent_source' => $slotState['order_intent_source'] ?? null,
            'order_intent_reason' => $slotState['order_intent_reason'] ?? null,
            'order_intent_confidence' => $slotState['order_intent_confidence'] ?? null,
            'single_item_review_pending' => (bool) ($slotState['single_item_review_pending'] ?? false),
            'single_item_review_completed' => (bool) ($slotState['single_item_review_completed'] ?? false),
            'single_item_just_confirmed' => (bool) ($slotState['single_item_just_confirmed'] ?? false),
            'multi_item_pending' => (bool) ($slotState['multi_item_pending'] ?? false),
            'multi_item_review_completed' => (bool) ($slotState['multi_item_review_completed'] ?? false),
            'multi_item_just_confirmed' => (bool) ($slotState['multi_item_just_confirmed'] ?? false),
            'slot_definitions' => $slotState['definitions'],
            'slot_values' => $slotState['slots'],
            'missing_slots' => $slotState['missing'],
            'next_slot' => $slotState['next'],
            'order_ready' => $slotState['order_ready'],
            'slot_summary' => $slotState['summary'],
            'order_draft' => $orderDraft,
            'updated_slots' => $slotState['updated_keys'],
        ];

        if ($handoff) {
            $this->syncConversationAiContext($conversation, $topic, $conversationAiContext);
            $this->setConversationAiEnabled($conversation, false, [
                'handoff_reason' => $handoffReason,
                'handoff_at' => now()->toIso8601String(),
                'status_code' => 'handoff_ai',
                'status_note' => $handoffReason !== ''
                    ? "AI передав діалог менеджеру: {$handoffReason}"
                    : 'AI передав діалог менеджеру.',
                'last_error' => null,
            ]);
        } else {
            $this->syncConversationAiContext($conversation, $topic, $conversationAiContext);
        }

        $this->markMessageAiState($message, [
            'status' => 'done',
            'topic_id' => $topic?->id,
            'topic_name' => $topic?->name,
            'requested_size' => $requestedSize,
            'photo_request' => $isPhotoRequest,
            'all_photo_request' => $isAllPhotosRequest,
            'awaiting_photo_confirmation' => $shouldAskPhotoConfirmation,
            'sent_media_count' => $sentMediaCount,
            'sent_text' => $sentText,
            'handoff' => $handoff,
            'handoff_reason' => $handoffReason,
            'topic_unresolved' => $isTopicUnclear,
            'topic_route_source' => $topicMatch['route_source'] ?? null,
            'topic_route_reason' => $topicMatch['route_reason'] ?? null,
            'topic_route_confidence' => $topicMatch['route_confidence'] ?? null,
            'media_intent' => $mediaIntentName,
            'media_intent_source' => $mediaIntent['source'] ?? null,
            'media_intent_reason' => $mediaIntent['reason'] ?? null,
            'media_intent_confidence' => $mediaIntent['confidence'] ?? null,
            'media_intent_target_color' => $mediaIntentColor,
            'order_intent' => $slotState['order_intent'] ?? null,
            'order_intent_source' => $slotState['order_intent_source'] ?? null,
            'order_intent_reason' => $slotState['order_intent_reason'] ?? null,
            'order_intent_confidence' => $slotState['order_intent_confidence'] ?? null,
            'single_item_review_pending' => (bool) ($slotState['single_item_review_pending'] ?? false),
            'single_item_review_completed' => (bool) ($slotState['single_item_review_completed'] ?? false),
            'single_item_just_confirmed' => (bool) ($slotState['single_item_just_confirmed'] ?? false),
            'multi_item_pending' => (bool) ($slotState['multi_item_pending'] ?? false),
            'multi_item_review_completed' => (bool) ($slotState['multi_item_review_completed'] ?? false),
            'multi_item_just_confirmed' => (bool) ($slotState['multi_item_just_confirmed'] ?? false),
            'slot_updates' => $slotState['updated'],
            'slot_values' => $slotState['slots'],
            'missing_slots' => $slotState['missing'],
            'next_slot' => $slotState['next'],
            'order_ready' => $slotState['order_ready'],
            'order_draft' => $orderDraft,
        ]);

        return [
            'status' => 'ok',
            'topic_id' => $topic?->id,
            'topic_name' => $topic?->name,
            'requested_size' => $requestedSize,
            'sent_media_count' => $sentMediaCount,
            'sent_text' => $sentText,
            'handoff' => $handoff,
        ];
    }

    private function resolveSkipReason(ChatMessage $message): ?string
    {
        $conversation = $message->conversation;
        if (!$conversation || !$conversation->contact || !$conversation->customer) {
            return 'conversation_context_missing';
        }

        if ($message->direction !== 'inbound') {
            return 'not_inbound';
        }

        if ($conversation->status !== 'open') {
            return 'conversation_not_open';
        }

        if (!$this->openAi->isConfigured()) {
            return 'openai_key_missing';
        }

        $runtimeSettings = $this->settingsService->resolveRuntimeSettings();
        if (!(bool) ($runtimeSettings['enabled'] ?? false)) {
            return 'ai_disabled_global';
        }

        if (!$this->isConversationAiEnabled($conversation)) {
            return 'ai_disabled_conversation';
        }

        if ((int) ($conversation->last_message_id ?? 0) !== (int) $message->id) {
            return 'not_last_message';
        }

        $alreadyProcessed = (bool) data_get($message->meta, 'ai.status');
        if ($alreadyProcessed) {
            return 'already_processed';
        }

        $hasOperatorReply = ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->where('source', 'operator')
            ->where('id', '>', $message->id)
            ->exists();

        if ($hasOperatorReply) {
            return 'operator_already_replied';
        }

        return null;
    }

    /**
     * @return Collection<int, ChatAiResponseRule>
     */
    private function loadActiveRules(): Collection
    {
        return ChatAiResponseRule::query()
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get(['id', 'code', 'title', 'instruction', 'priority']);
    }

    /**
     * @return Collection<int, ChatAiTopic>
     */
    private function loadActiveTopics(): Collection
    {
        return ChatAiTopic::query()
            ->where('is_active', true)
            ->with([
                'keywords' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderByDesc('weight'),
                'topicProducts' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->with([
                        'product' => fn ($productQuery) => $productQuery
                            ->where('is_active', true)
                            ->with([
                                'color:id,name',
                                'variants' => fn ($variantQuery) => $variantQuery
                                    ->where('is_active', true)
                                    ->orderBy('id'),
                            ]),
                    ]),
                'mediaItems' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->with('savedFile:id,url,type,filename'),
            ])
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, ChatAiTopic>  $topics
     * @return array{topic: ?ChatAiTopic, score: int, route_source: string, route_reason: ?string, route_confidence: ?float}
     */
    private function resolveTopicMatch(
        Collection $topics,
        string $text,
        ChatConversation $conversation,
        array $settings
    ): array {
        if ($this->shouldForceModelSelection($topics, $text, $conversation)) {
            return [
                'topic' => null,
                'score' => 0,
                'route_source' => 'needs_model_selection',
                'route_reason' => 'Клієнт назвав лише розмір без вибору моделі.',
                'route_confidence' => null,
            ];
        }

        $keywordMatch = $this->matchTopic($topics, $text, $conversation);

        if (!$this->shouldUseAiTopicClassifier($topics, $text, $conversation, $keywordMatch)) {
            return $keywordMatch;
        }

        try {
            $aiMatch = $this->classifyTopicWithAi($topics, $text, $conversation, $settings);
            if ($aiMatch !== null) {
                return $aiMatch;
            }
        } catch (\Throwable $e) {
            Log::warning('AI: не вдалося визначити тему через classifier', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $keywordMatch;
    }

    /**
     * @param  Collection<int, ChatAiTopic>  $topics
     * @param  Collection<int, array<string, mixed>>  $products
     * @param  array<string, mixed>  $slotState
     * @param  Collection<int, array<string, mixed>>  $overviewMedia
     * @return array{
     *     intent: string,
     *     source: string,
     *     reason: ?string,
     *     confidence: ?float,
     *     target_color: ?string
     * }
     */
    private function resolveMediaIntent(
        Collection $topics,
        ?ChatAiTopic $topic,
        Collection $products,
        array $slotState,
        ChatConversation $conversation,
        string $text,
        array $settings,
        Collection $overviewMedia,
        bool $explicitPhotoRequest,
        bool $ambiguousVisualIntent,
        bool $confirmedPhotoRequest,
        bool $declinedPhotoRequest,
        bool $hasResolvedVisualContext
    ): array {
        $fallback = $this->resolveMediaIntentFallback(
            $topic,
            $slotState,
            $conversation,
            $text,
            $overviewMedia,
            $explicitPhotoRequest,
            $ambiguousVisualIntent,
            $hasResolvedVisualContext
        );

        if ($confirmedPhotoRequest || $declinedPhotoRequest) {
            return $fallback;
        }

        if (!$this->shouldUseAiMediaIntentClassifier(
            $topics,
            $topic,
            $slotState,
            $conversation,
            $text,
            $explicitPhotoRequest,
            $ambiguousVisualIntent
        )) {
            return $fallback;
        }

        try {
            $aiIntent = $this->classifyMediaIntentWithAi(
                $topics,
                $topic,
                $products,
                $slotState,
                $conversation,
                $text,
                $settings
            );
            if ($aiIntent !== null) {
                return $aiIntent;
            }
        } catch (\Throwable $e) {
            Log::warning('AI: не вдалося визначити медіа-intent через classifier', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $fallback;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $products
     * @param  array<string, mixed>  $slotState
     * @return array{
     *     intent: string,
     *     source: string,
     *     reason: ?string,
     *     confidence: ?float,
     *     target_color: ?string
     * }|null
     */
    private function classifyMediaIntentWithAi(
        Collection $topics,
        ?ChatAiTopic $topic,
        Collection $products,
        array $slotState,
        ChatConversation $conversation,
        string $text,
        array $settings
    ): ?array {
        $currentModel = $this->resolveCurrentModelContextName($topic, $slotState, $conversation);
        $currentColor = $this->normalizeSlotValue('color', data_get($slotState, 'slots.color'));
        $currentSize = $this->normalizeSlotValue('size', data_get($slotState, 'slots.size'));
        $nextSlot = is_string($slotState['next'] ?? null) ? $slotState['next'] : null;
        $availableModels = $this->buildAvailableTopicList($topics);
        $availableColors = $products
            ->pluck('color_name')
            ->map(fn ($value) => $this->normalizeSlotValue('color', $value))
            ->filter()
            ->unique()
            ->values()
            ->implode(', ');
        $currentPhotoCount = $products
            ->filter(fn (array $product) => (bool) ($product['has_photo'] ?? false))
            ->count();
        $memory = $this->buildConversationMemoryBlock($conversation);
        $history = $this->buildHistoryForPrompt($conversation->id, 6);

        $input = implode("\n\n", array_filter([
            'Останнє повідомлення клієнта: ' . trim($text),
            $memory,
            'Поточний стан:'
                . "\n- модель вибрана: " . ($currentModel !== null ? 'так' : 'ні')
                . ($currentModel !== null ? "\n- поточна модель: {$currentModel}" : '')
                . ($currentColor !== null ? "\n- поточний колір: {$currentColor}" : '')
                . ($currentSize !== null ? "\n- поточний розмір: {$currentSize}" : '')
                . ($nextSlot !== null ? "\n- наступний слот: {$nextSlot}" : ''),
            $availableModels !== '' ? "Доступні моделі:\n{$availableModels}" : null,
            $availableColors !== '' ? "Доступні кольори поточної моделі: {$availableColors}" : null,
            $currentPhotoCount > 0 ? "Кількість фото поточної моделі: {$currentPhotoCount}" : 'Фото поточної моделі: немає',
            "Останні повідомлення діалогу:\n{$history}",
        ]));

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'intent' => [
                    'type' => 'string',
                    'enum' => [
                        'none',
                        'show_models',
                        'show_all_current_model_photos',
                        'show_specific_color_photo',
                        'confirm_photo',
                    ],
                ],
                'confidence' => [
                    'type' => 'number',
                    'minimum' => 0,
                    'maximum' => 1,
                ],
                'reason' => [
                    'type' => 'string',
                ],
                'target_color' => [
                    'type' => ['string', 'null'],
                ],
            ],
            'required' => ['intent', 'confidence', 'reason', 'target_color'],
        ];

        $instructions = implode("\n", [
            'Ти визначаєш намір клієнта для вибору моделей і фото товарів.',
            'Поверни тільки один intent із дозволеного списку.',
            'Якщо модель ще не вибрана і клієнт пише нечітко, коротко, називає тільки розмір, ціну або просить "показати/які є" без конкретної моделі — intent = show_models.',
            'Якщо модель уже вибрана і клієнт просить показати ще, всі варіанти, всі кольори, всі фото або що ще є по цій моделі — intent = show_all_current_model_photos.',
            'Якщо модель уже вибрана і клієнт просить показати конкретний колір — intent = show_specific_color_photo.',
            'Якщо клієнт натякає, що хоче подивитися, але не зовсім прямо просить фото, а в діалозі вже є зрозуміла модель або колір — intent = confirm_photo.',
            'Якщо повідомлення не про вибір моделі чи фото, а про ціну, розмір, кількість, доставку, оплату або оформлення — intent = none.',
            'Якщо є target_color, поверни канонічну назву кольору українською. Якщо кольору не видно — поверни null.',
            'Не вигадуй нових моделей чи кольорів. Орієнтуйся тільки на переданий контекст.',
        ]);

        $response = $this->openAi->createStructuredResponse(
            $instructions,
            $input,
            $schema,
            'chat_media_intent_router',
            (string) ($settings['model'] ?? '')
        );

        $intent = trim((string) ($response['intent'] ?? ''));
        $confidence = isset($response['confidence']) ? (float) $response['confidence'] : 0.0;
        $reason = trim((string) ($response['reason'] ?? ''));
        $targetColor = $this->normalizeSlotValue('color', $response['target_color'] ?? null);
        $normalizedText = $this->normalizeText($text);

        if (!in_array($intent, ['none', 'show_models', 'show_all_current_model_photos', 'show_specific_color_photo', 'confirm_photo'], true)) {
            return null;
        }

        if ($confidence < 0.58) {
            return null;
        }

        // Якщо колір уже обраний, а клієнт просто просить показати фото без слів
        // "ще / всі / варіанти / кольори", показуємо один конкретний варіант.
        if (
            $intent === 'show_all_current_model_photos'
            && $currentColor !== null
            && $this->isPhotoRequest($text)
            && !$this->isAllPhotosRequest($text)
            && !$this->isBroadCatalogRequest($normalizedText)
        ) {
            $intent = 'show_specific_color_photo';
            $targetColor = $currentColor;
            $reason = 'Клієнт просить показати фото вже вибраного кольору поточної моделі.';
        }

        return [
            'intent' => $intent,
            'source' => 'ai_classifier',
            'reason' => $reason !== '' ? $reason : 'Намір визначено AI-класифікатором.',
            'confidence' => round($confidence, 3),
            'target_color' => $targetColor,
        ];
    }

    /**
     * @param  Collection<int, ChatAiTopic>  $topics
     * @param  array<string, mixed>  $slotState
     */
    private function shouldUseAiMediaIntentClassifier(
        Collection $topics,
        ?ChatAiTopic $topic,
        array $slotState,
        ChatConversation $conversation,
        string $text,
        bool $explicitPhotoRequest,
        bool $ambiguousVisualIntent
    ): bool {
        if ($topics->isEmpty()) {
            return false;
        }

        $normalized = $this->normalizeText($text);
        if ($normalized === '') {
            return false;
        }

        $nextSlot = is_string($slotState['next'] ?? null) ? $slotState['next'] : null;
        if ($nextSlot === 'model') {
            return true;
        }

        if ($explicitPhotoRequest || $ambiguousVisualIntent) {
            return true;
        }

        if (!$this->hasSelectedModelContext($topic, $slotState, $conversation)) {
            return false;
        }

        return (bool) preg_match(
            '/(які|якi|яки|ще|всі|усі|все|усе|кольор|варіант|покажи|показ|глянут|подив|побач|скинь|надіш|надіш|можна|фото)/u',
            $normalized
        );
    }

    /**
     * @param  array<string, mixed>  $slotState
     * @param  Collection<int, array<string, mixed>>  $overviewMedia
     * @return array{
     *     intent: string,
     *     source: string,
     *     reason: ?string,
     *     confidence: ?float,
     *     target_color: ?string
     * }
     */
    private function resolveMediaIntentFallback(
        ?ChatAiTopic $topic,
        array $slotState,
        ChatConversation $conversation,
        string $text,
        Collection $overviewMedia,
        bool $explicitPhotoRequest,
        bool $ambiguousVisualIntent,
        bool $hasResolvedVisualContext
    ): array {
        $normalized = $this->normalizeText($text);
        $hasSelectedModelContext = $this->hasSelectedModelContext($topic, $slotState, $conversation);
        $nextSlot = is_string($slotState['next'] ?? null) ? $slotState['next'] : null;
        $targetColor = $this->normalizeSlotValue('color', data_get($slotState, 'slots.color'))
            ?? $this->normalizeSlotValue('color', $this->extractColorValue($text, $nextSlot));

        if (
            !$hasSelectedModelContext
            && $overviewMedia->isNotEmpty()
            && $normalized !== ''
            && $nextSlot === 'model'
        ) {
            return [
                'intent' => 'show_models',
                'source' => 'fallback',
                'reason' => 'Модель ще не вибрана, показуємо колажі моделей.',
                'confidence' => null,
                'target_color' => null,
            ];
        }

        if ($hasSelectedModelContext) {
            if ($this->shouldSendAllCurrentModelPhotos($text, $explicitPhotoRequest, $slotState, $topic, $conversation)) {
                return [
                    'intent' => 'show_all_current_model_photos',
                    'source' => 'fallback',
                    'reason' => 'Поточна модель уже вибрана, показуємо всі фото цієї моделі.',
                    'confidence' => null,
                    'target_color' => null,
                ];
            }

            if ($explicitPhotoRequest) {
                return [
                    'intent' => 'show_specific_color_photo',
                    'source' => 'fallback',
                    'reason' => 'Клієнт прямо просить фото по поточній моделі.',
                    'confidence' => null,
                    'target_color' => $targetColor,
                ];
            }

            if ($ambiguousVisualIntent && $hasResolvedVisualContext) {
                return [
                    'intent' => 'confirm_photo',
                    'source' => 'fallback',
                    'reason' => 'Потрібно коротко уточнити, чи клієнт справді хоче переглянути фото.',
                    'confidence' => null,
                    'target_color' => $targetColor,
                ];
            }
        }

        if (
            !$hasSelectedModelContext
            && $overviewMedia->isNotEmpty()
            && ($explicitPhotoRequest || $this->isBroadCatalogRequest($normalized) || $this->isAllPhotosRequest($text))
        ) {
            return [
                'intent' => 'show_models',
                'source' => 'fallback',
                'reason' => 'Клієнт просить показати варіанти до вибору моделі.',
                'confidence' => null,
                'target_color' => null,
            ];
        }

        return [
            'intent' => 'none',
            'source' => 'fallback',
            'reason' => 'Окремий медіа-наміру не виявлено.',
            'confidence' => null,
            'target_color' => $targetColor,
        ];
    }

    /**
     * @param  Collection<int, ChatAiTopic>  $topics
     */
    private function shouldForceModelSelection(Collection $topics, string $text, ChatConversation $conversation): bool
    {
        if ($topics->count() < 2) {
            return false;
        }

        $normalizedText = $this->normalizeText($text);
        if ($normalizedText === '') {
            return false;
        }

        $storedModel = $this->normalizeSlotValue('model', data_get($conversation->meta, 'ai.slot_values.model'));
        $nextSlot = data_get($conversation->meta, 'ai.next_slot');

        if ($storedModel !== null && (!is_string($nextSlot) || $nextSlot !== 'model')) {
            return false;
        }

        if ($this->extractRequestedSize($text) === null) {
            return false;
        }

        if ($this->extractVisualPreferenceStems($normalizedText) !== []) {
            return false;
        }

        if ((bool) preg_match('/(домашн|для вулиці|на вулицю|вуличн|резинов|гумов|суцільн|літні?)/u', $normalizedText)) {
            return false;
        }

        return !$this->messageContainsModelReference($normalizedText);
    }

    /**
     * @param  Collection<int, ChatAiTopic>  $topics
     * @param  array{topic: ?ChatAiTopic, score: int, route_source: string, route_reason: ?string, route_confidence: ?float}  $keywordMatch
     */
    private function shouldUseAiTopicClassifier(
        Collection $topics,
        string $text,
        ChatConversation $conversation,
        array $keywordMatch
    ): bool {
        if ($topics->count() < 2) {
            return false;
        }

        $normalizedText = $this->normalizeText($text);
        if ($normalizedText === '') {
            return false;
        }

        if ($this->isBroadCatalogRequest($normalizedText)) {
            return false;
        }

        $keywordScore = (int) ($keywordMatch['score'] ?? 0);
        $routeSource = (string) ($keywordMatch['route_source'] ?? '');
        $topicUnresolved = (bool) data_get($conversation->meta, 'ai.topic_unresolved', false);

        if ($keywordScore <= 0 || $routeSource === 'last_topic_fallback') {
            return true;
        }

        return $topicUnresolved && $keywordScore < 100;
    }

    /**
     * @param  Collection<int, ChatAiTopic>  $topics
     * @return array{topic: ?ChatAiTopic, score: int, route_source: string, route_reason: ?string, route_confidence: ?float}|null
     */
    private function classifyTopicWithAi(
        Collection $topics,
        string $text,
        ChatConversation $conversation,
        array $settings
    ): ?array {
        $topicBlock = $topics
            ->map(function (ChatAiTopic $topic) {
                $positiveKeywords = $topic->keywords
                    ->where('is_active', true)
                    ->where('match_type', 'positive')
                    ->pluck('phrase')
                    ->filter(fn ($phrase) => trim((string) $phrase) !== '')
                    ->implode('; ');

                $negativeKeywords = $topic->keywords
                    ->where('is_active', true)
                    ->where('match_type', 'negative')
                    ->pluck('phrase')
                    ->filter(fn ($phrase) => trim((string) $phrase) !== '')
                    ->implode('; ');

                $mediaLabels = $topic->mediaItems
                    ->where('is_active', true)
                    ->pluck('label')
                    ->filter(fn ($label) => trim((string) $label) !== '')
                    ->take(5)
                    ->implode('; ');

                $productTitles = $topic->topicProducts
                    ->filter(fn ($topicProduct) => (bool) $topicProduct->is_active && $topicProduct->product !== null)
                    ->map(fn ($topicProduct) => trim((string) ($topicProduct->product?->title ?? '')))
                    ->filter()
                    ->take(5)
                    ->implode('; ');

                $instruction = trim((string) $topic->instruction);
                $instruction = $instruction !== '' ? Str::limit($instruction, 320, '') : 'Опис теми не заданий.';

                return implode("\n", array_filter([
                    "ID: {$topic->id}",
                    "Назва: {$topic->name}",
                    "Опис: {$instruction}",
                    $positiveKeywords !== '' ? "Позитивні підказки: {$positiveKeywords}" : null,
                    $negativeKeywords !== '' ? "Негативні підказки: {$negativeKeywords}" : null,
                    $mediaLabels !== '' ? "Назви медіа: {$mediaLabels}" : null,
                    $productTitles !== '' ? "Приклади товарів: {$productTitles}" : null,
                ]));
            })
            ->implode("\n\n");

        $memory = $this->buildConversationMemoryBlock($conversation);
        $history = $this->buildHistoryForPrompt($conversation->id, 6);
        $input = implode("\n\n", array_filter([
            "Останнє повідомлення клієнта: " . trim($text),
            $memory,
            "Останні повідомлення діалогу:\n{$history}",
            "Доступні теми:\n{$topicBlock}",
        ]));

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'topic_id' => [
                    'type' => ['integer', 'null'],
                ],
                'confidence' => [
                    'type' => 'number',
                    'minimum' => 0,
                    'maximum' => 1,
                ],
                'reason' => [
                    'type' => 'string',
                ],
            ],
            'required' => ['topic_id', 'confidence', 'reason'],
        ];

        $instructions = implode("\n", [
            'Ти визначаєш тему товару для AI-продавця.',
            'Обирай тему по змісту повідомлення, короткій пам’яті діалогу та останній історії розмови.',
            'Не покладайся тільки на точні keywords, використовуй зміст і контекст.',
            'Використовуй як семантичні підказки також назви колажів, фото і товарів, якщо вони є в описі теми.',
            'Якщо клієнт явно вибрав один із варіантів, які AI щойно запропонував, віднеси це до відповідної теми.',
            'Якщо інформації недостатньо і тема неочевидна, повертай topic_id = null.',
            'Не вигадуй нові теми. Обирай тільки з доступного списку.',
        ]);

        $response = $this->openAi->createStructuredResponse(
            $instructions,
            $input,
            $schema,
            'chat_topic_router',
            (string) ($settings['model'] ?? '')
        );

        $topicId = isset($response['topic_id']) ? (int) $response['topic_id'] : 0;
        $confidence = isset($response['confidence']) ? (float) $response['confidence'] : 0.0;
        $reason = trim((string) ($response['reason'] ?? ''));

        if ($topicId <= 0 || $confidence < 0.55) {
            return null;
        }

        $topic = $topics->firstWhere('id', $topicId);
        if (!$topic) {
            return null;
        }

        return [
            'topic' => $topic,
            'score' => 1000,
            'route_source' => 'ai_classifier',
            'route_reason' => $reason !== '' ? $reason : 'Тему визначено AI-класифікатором.',
            'route_confidence' => round($confidence, 3),
        ];
    }

    /**
     * @param  Collection<int, ChatAiTopic>  $topics
     * @return array{topic: ?ChatAiTopic, score: int, route_source: string, route_reason: ?string, route_confidence: ?float}
     */
    private function matchTopic(Collection $topics, string $text, ChatConversation $conversation): array
    {
        if ($topics->isEmpty()) {
            return [
                'topic' => null,
                'score' => 0,
                'route_source' => 'unresolved',
                'route_reason' => 'Активні теми відсутні.',
                'route_confidence' => null,
            ];
        }

        $normalizedText = $this->normalizeText($text);
        $isBroadCatalogRequest = $this->isBroadCatalogRequest($normalizedText);
        $lastTopicId = (int) data_get($conversation->meta, 'ai.last_topic_id', 0);
        $storedModel = $this->normalizeSlotValue('model', data_get($conversation->meta, 'ai.slot_values.model'));
        $nextSlot = data_get($conversation->meta, 'ai.next_slot');
        $canReuseLastTopicContext = $storedModel !== null
            && is_string($nextSlot)
            && $nextSlot !== 'model';

        $bestTopic = null;
        $bestScore = PHP_INT_MIN;

        foreach ($topics as $topic) {
            $score = 0;

            if (
                !$isBroadCatalogRequest
                && $canReuseLastTopicContext
                && $lastTopicId > 0
                && $lastTopicId === (int) $topic->id
            ) {
                $score += 40;
            }

            $topicName = $this->normalizeText($topic->name);
            if ($topicName !== '' && str_contains($normalizedText, $topicName)) {
                $score += 35;
            }

            foreach ($topic->keywords as $keyword) {
                $phrases = $this->explodeKeywordPhrase((string) $keyword->phrase);
                $weight = max(1, (int) $keyword->weight);

                foreach ($phrases as $phrase) {
                    if (!str_contains($normalizedText, $phrase)) {
                        continue;
                    }

                    if ($keyword->match_type === 'negative') {
                        $score -= $weight * 2;
                    } else {
                        $score += $weight;
                    }
                }
            }

            if ($score > $bestScore) {
                $bestTopic = $topic;
                $bestScore = $score;
                continue;
            }

            if ($score === $bestScore && $bestTopic && $topic->priority < $bestTopic->priority) {
                $bestTopic = $topic;
            }
        }

        if (!$bestTopic) {
            return [
                'topic' => null,
                'score' => 0,
                'route_source' => 'unresolved',
                'route_reason' => 'Не знайдено найкращу тему.',
                'route_confidence' => null,
            ];
        }

        // Якщо запит загальний ("які маєте", "що є в наявності"), не тягнемо попередню тему:
        // потрібно показати оглядові варіанти.
        if ($bestScore <= 0 && $isBroadCatalogRequest) {
            if ($canReuseLastTopicContext && $lastTopicId > 0) {
                $fallback = $topics->firstWhere('id', $lastTopicId);
                if ($fallback) {
                    return [
                        'topic' => $fallback,
                        'score' => 2,
                        'route_source' => 'current_model_context',
                        'route_reason' => 'Загальний запит інтерпретовано в межах уже вибраної моделі.',
                        'route_confidence' => null,
                    ];
                }
            }

            return [
                'topic' => null,
                'score' => $bestScore,
                'route_source' => 'broad_catalog_unresolved',
                'route_reason' => 'Загальний запит без конкретної теми.',
                'route_confidence' => null,
            ];
        }

        if ($bestScore <= 0 && $topics->count() === 1) {
            return [
                'topic' => $topics->first(),
                'score' => 1,
                'route_source' => 'single_topic_fallback',
                'route_reason' => 'Активна лише одна тема.',
                'route_confidence' => null,
            ];
        }

        if ($bestScore <= 0 && $lastTopicId > 0) {
            $fallback = $topics->firstWhere('id', $lastTopicId);
            if ($fallback) {
                return [
                    'topic' => $fallback,
                    'score' => 1,
                    'route_source' => 'last_topic_fallback',
                    'route_reason' => 'Використано останню тему з пам’яті діалогу.',
                    'route_confidence' => null,
                ];
            }
        }

        if ($bestScore <= 0) {
            return [
                'topic' => null,
                'score' => $bestScore,
                'route_source' => 'keywords_unresolved',
                'route_reason' => 'Keywords не дали достатнього збігу.',
                'route_confidence' => null,
            ];
        }

        return [
            'topic' => $bestTopic,
            'score' => $bestScore,
            'route_source' => 'keywords',
            'route_reason' => 'Тему визначено за назвою, keywords або контекстом останньої теми.',
            'route_confidence' => null,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function resolveTopicProducts(ChatAiTopic $topic, ?int $requestedSize): Collection
    {
        $rows = $topic->topicProducts
            ->filter(fn ($topicProduct) => $topicProduct->product !== null)
            ->map(function ($topicProduct) use ($requestedSize) {
                /** @var Product $product */
                $product = $topicProduct->product;
                $activeVariants = $product->variants
                    ->filter(fn (ProductVariant $variant) => (bool) $variant->is_active)
                    ->values();

                $availableVariants = $activeVariants
                    ->filter(fn (ProductVariant $variant) => (int) ($variant->stock_qty ?? 0) > 0)
                    ->values();

                $variantInventory = $activeVariants
                    ->map(function (ProductVariant $variant) {
                        $sizeLabel = $this->normalizeSizeSlotValue((string) ($variant->size ?? ''));
                        if ($sizeLabel === null) {
                            $sizeLabel = trim((string) $variant->size);
                        }

                        return [
                            'size' => $sizeLabel,
                            'stock_qty' => max(0, (int) ($variant->stock_qty ?? 0)),
                            'is_available' => (int) ($variant->stock_qty ?? 0) > 0,
                        ];
                    })
                    ->filter(fn (array $variant) => trim((string) ($variant['size'] ?? '')) !== '')
                    ->values();

                $availableSizes = $variantInventory
                    ->filter(fn (array $variant) => (bool) ($variant['is_available'] ?? false))
                    ->pluck('size')
                    ->unique()
                    ->values()
                    ->all();

                $allSizes = $variantInventory
                    ->pluck('size')
                    ->unique()
                    ->values()
                    ->all();

                $matchesAvailableSize = $requestedSize === null
                    ? true
                    : $this->productMatchesRequestedSize($availableVariants, $requestedSize);

                $matchesAnySize = $requestedSize === null
                    ? true
                    : $this->productMatchesRequestedSize($activeVariants, $requestedSize);

                $mainPhotoUrl = $this->absoluteUrl($product->main_photo_url);
                $totalAvailableStock = (int) $variantInventory
                    ->filter(fn (array $variant) => (bool) ($variant['is_available'] ?? false))
                    ->sum('stock_qty');

                return [
                    'topic_product_id' => (int) $topicProduct->id,
                    'product_id' => (int) $product->id,
                    'title' => (string) $product->title,
                    'color_name' => $product->color?->name
                        ? $this->normalizeHumanLabel((string) $product->color->name, 40)
                        : $this->extractKnownColorFromText((string) $product->title),
                    'sku' => $product->sku ? (string) $product->sku : null,
                    'price' => $product->sale_price !== null ? (float) $product->sale_price : null,
                    'main_photo_url' => $mainPhotoUrl,
                    'main_photo_path' => $product->main_photo_path ? (string) $product->main_photo_path : null,
                    'has_photo' => $mainPhotoUrl !== null,
                    'available_sizes' => $availableSizes,
                    'all_sizes' => $allSizes,
                    'sizes' => $availableSizes !== [] ? $availableSizes : $allSizes,
                    'variant_inventory' => $variantInventory->all(),
                    'is_available' => $totalAvailableStock > 0,
                    'total_stock_qty' => $totalAvailableStock,
                    'sort_order' => (int) $topicProduct->sort_order,
                    'is_active' => (bool) $topicProduct->is_active,
                    'matches_available_size' => $matchesAvailableSize,
                    'matches_size' => $matchesAnySize,
                    'requested_size_stock_qty' => $requestedSize !== null
                        ? $this->resolveRequestedSizeStock($variantInventory, $requestedSize)
                        : null,
                ];
            })
            ->sortBy([
                ['matches_available_size', 'desc'],
                ['matches_size', 'desc'],
                ['has_photo', 'desc'],
                ['is_available', 'desc'],
                ['sort_order', 'asc'],
                ['product_id', 'asc'],
            ])
            ->values();

        if ($requestedSize !== null) {
            $filtered = $rows->where('matches_available_size', true)->values();
            if ($filtered->isNotEmpty()) {
                return $filtered;
            }

            $filtered = $rows->where('matches_size', true)->values();
            if ($filtered->isNotEmpty()) {
                return $filtered;
            }
        }

        return $rows;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $products
     * @return Collection<int, array<string, mixed>>
     */
    private function resolveTopicMedia(ChatAiTopic $topic, Collection $products): Collection
    {
        $media = collect();

        foreach ($topic->mediaItems as $mediaItem) {
            $mediaType = (string) $mediaItem->media_type;
            if (!in_array($mediaType, ['collage', 'palette'], true)) {
                continue;
            }

            $url = $this->absoluteUrl($mediaItem->url ?: $mediaItem->savedFile?->url);
            if (!$url) {
                continue;
            }

            $media->push([
                'source' => 'topic_overview_media',
                'topic_media_id' => (int) $mediaItem->id,
                'media_type' => $mediaType,
                'label' => trim((string) $mediaItem->label),
                'color_name' => $this->extractKnownColorFromText((string) $mediaItem->label),
                'url' => $url,
                'sort_order' => (int) $mediaItem->sort_order,
            ]);
        }

        foreach ($products as $product) {
            $url = (string) ($product['main_photo_url'] ?? '');
            if ($url === '') {
                continue;
            }

            $media->push([
                'source' => 'product_photo',
                'topic_media_id' => null,
                'media_type' => 'image',
                'label' => (string) ($product['title'] ?? ''),
                'color_name' => isset($product['color_name']) ? (string) $product['color_name'] : null,
                'url' => $url,
                'sort_order' => (int) ($product['sort_order'] ?? 0),
                'topic_product_id' => (int) ($product['topic_product_id'] ?? 0),
                'product_id' => (int) ($product['product_id'] ?? 0),
            ]);
        }

        return $media
            ->unique(fn (array $item) => $item['url'])
            ->values();
    }

    /**
     * @param  Collection<int, ChatAiTopic>  $topics
     * @return Collection<int, array<string, mixed>>
     */
    private function resolveAllTopicsOverviewMedia(Collection $topics): Collection
    {
        $overview = collect();

        foreach ($topics as $topic) {
            $topicMedia = $topic->mediaItems
                ->filter(fn ($item) => in_array((string) $item->media_type, ['collage', 'palette'], true))
                ->sortBy('sort_order')
                ->values();

            if ($topicMedia->isNotEmpty()) {
                foreach ($topicMedia as $mediaItem) {
                    $url = $this->absoluteUrl($mediaItem->url ?: $mediaItem->savedFile?->url);
                    if (!$url) {
                        continue;
                    }

                    $overview->push([
                        'source' => 'topic_overview_media',
                        'topic_media_id' => (int) $mediaItem->id,
                        'media_type' => (string) $mediaItem->media_type,
                        'label' => trim((string) $mediaItem->label) !== ''
                            ? trim((string) $mediaItem->label)
                            : "Модель: {$topic->name}",
                        'url' => $url,
                        'sort_order' => (int) $mediaItem->sort_order,
                        'topic_id' => (int) $topic->id,
                        'topic_name' => (string) $topic->name,
                        'topic_priority' => (int) $topic->priority,
                    ]);
                }
            }

            if ($topicMedia->isNotEmpty()) {
                continue;
            }

            $primaryTopicMedia = $topic->mediaItems
                ->sortBy('sort_order')
                ->first();

            if ($primaryTopicMedia) {
                $url = $this->absoluteUrl($primaryTopicMedia->url ?: $primaryTopicMedia->savedFile?->url);
                if ($url) {
                    $overview->push([
                        'source' => 'topic_overview_media',
                        'topic_media_id' => (int) $primaryTopicMedia->id,
                        'media_type' => (string) $primaryTopicMedia->media_type,
                        'label' => trim((string) $primaryTopicMedia->label) !== ''
                            ? trim((string) $primaryTopicMedia->label)
                            : "Модель: {$topic->name}",
                        'url' => $url,
                        'sort_order' => (int) $primaryTopicMedia->sort_order,
                        'topic_id' => (int) $topic->id,
                        'topic_name' => (string) $topic->name,
                        'topic_priority' => (int) $topic->priority,
                    ]);
                }
            }

            if ($primaryTopicMedia) {
                continue;
            }

            $fallbackProduct = $topic->topicProducts
                ->filter(fn ($topicProduct) => $topicProduct->product !== null)
                ->sortBy('sort_order')
                ->first();

            if (!$fallbackProduct || !$fallbackProduct->product) {
                continue;
            }

            $fallbackUrl = $this->absoluteUrl($fallbackProduct->product->main_photo_url);
            if (!$fallbackUrl) {
                continue;
            }

            $overview->push([
                'source' => 'topic_overview_product',
                'topic_media_id' => null,
                'media_type' => 'image',
                'label' => "Модель: {$topic->name}",
                'url' => $fallbackUrl,
                'sort_order' => (int) $fallbackProduct->sort_order,
                'topic_product_id' => (int) $fallbackProduct->id,
                'product_id' => (int) $fallbackProduct->product_id,
                'topic_id' => (int) $topic->id,
                'topic_name' => (string) $topic->name,
                'topic_priority' => (int) $topic->priority,
            ]);
        }

        return $overview
            ->unique(fn (array $item) => (string) ($item['url'] ?? ''))
            ->sortBy([
                ['topic_priority', 'asc'],
                ['sort_order', 'asc'],
                ['topic_id', 'asc'],
            ])
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $media
     * @return Collection<int, array<string, mixed>>
     */
    private function selectMediaForReply(Collection $media, string $messageText, bool $sendAll): Collection
    {
        if ($media->isEmpty()) {
            return collect();
        }

        $query = $this->normalizeText($messageText);
        $requestedColor = $this->extractKnownColorFromText($query);
        $requestedColorSearch = $requestedColor !== null
            ? $this->normalizeText($requestedColor)
            : null;
        $visualPreferenceStems = $this->extractVisualPreferenceStems($query);
        $explicitOverviewRequest = str_contains($query, 'колаж')
            || str_contains($query, 'палiтр')
            || str_contains($query, 'палітр');
        $tokens = collect(preg_split('/[^[:alnum:]]+/u', $query))
            ->filter(fn ($token) => mb_strlen((string) $token) >= 4)
            ->values();

        $ranked = $media
            ->map(function (array $item) use ($query, $tokens, $visualPreferenceStems, $explicitOverviewRequest, $requestedColorSearch) {
                $label = $this->normalizeText((string) ($item['label'] ?? ''));
                $itemColorSearch = $this->normalizeText((string) ($item['color_name'] ?? ''));
                $source = (string) ($item['source'] ?? '');
                $score = 0;

                if ($label !== '' && $query !== '') {
                    if (str_contains($label, $query) || str_contains($query, $label)) {
                        $score += 100;
                    }
                }

                foreach ($tokens as $token) {
                    if ($label !== '' && str_contains($label, (string) $token)) {
                        $score += 20;
                    }
                }

                foreach ($visualPreferenceStems as $stem) {
                    if ($label !== '' && str_contains($label, $stem)) {
                        $score += 90;
                    }
                }

                if ($requestedColorSearch !== null && $requestedColorSearch !== '') {
                    if ($itemColorSearch === $requestedColorSearch) {
                        $score += 240;
                    } elseif ($itemColorSearch !== '') {
                        $score -= 120;
                    }
                }

                if ($explicitOverviewRequest) {
                    if (in_array(($item['media_type'] ?? ''), ['collage', 'palette'], true)) {
                        $score += 220;
                    } elseif ($source === 'product_photo') {
                        $score -= 140;
                    }
                } else {
                    if ($source === 'product_photo') {
                        $score += 280;
                    } elseif (in_array(($item['media_type'] ?? ''), ['collage', 'palette'], true)) {
                        $score -= 220;
                    }
                }

                if (
                    in_array(($item['media_type'] ?? ''), ['collage', 'palette'], true)
                    && $explicitOverviewRequest
                ) {
                    $score += 40;
                }

                if (
                    $label !== ''
                    && $visualPreferenceStems !== []
                    && in_array((string) ($item['media_type'] ?? ''), ['collage', 'palette'], true)
                ) {
                    $score -= 50;
                }

                $item['score'] = $score;

                return $item;
            })
            ->sortBy([
                ['score', 'desc'],
                ['sort_order', 'asc'],
            ])
            ->values();

        $positive = $ranked
            ->filter(fn (array $item) => (int) ($item['score'] ?? 0) > 0)
            ->values();

        if ($sendAll) {
            $specificPositive = $positive
                ->filter(fn (array $item) => (string) ($item['source'] ?? '') === 'product_photo')
                ->values();

            if ($explicitOverviewRequest) {
                $overviewPositive = $positive
                    ->filter(fn (array $item) => in_array((string) ($item['media_type'] ?? ''), ['collage', 'palette'], true))
                    ->values();

                return ($overviewPositive->isNotEmpty() ? $overviewPositive : $positive)->values();
            }

            return ($specificPositive->isNotEmpty() ? $specificPositive : collect())->values();
        }

        if ($positive->isNotEmpty()) {
            if ($explicitOverviewRequest) {
                $overviewMedia = $positive
                    ->filter(fn (array $item) => in_array((string) ($item['media_type'] ?? ''), ['collage', 'palette'], true))
                    ->values();

                return ($overviewMedia->isNotEmpty() ? $overviewMedia : $positive)
                    ->take(1)
                    ->values();
            }

            $specificMedia = $positive
                ->filter(fn (array $item) => (string) ($item['source'] ?? '') === 'product_photo')
                ->values();

            return $specificMedia
                ->take(1)
                ->values();
        }

        if ($visualPreferenceStems !== []) {
            return collect();
        }

        $specificMedia = $ranked
            ->filter(fn (array $item) => (string) ($item['source'] ?? '') === 'product_photo')
            ->values();

        if ($specificMedia->isNotEmpty()) {
            return $specificMedia->take(1)->values();
        }

        return $explicitOverviewRequest
            ? $ranked->take(1)->values()
            : collect();
    }

    /**
     * @param  Collection<int, ChatAiResponseRule>  $rules
     * @param  Collection<int, array<string, mixed>>  $products
     * @param  Collection<int, array<string, mixed>>  $selectedMedia
     * @param  array<string, mixed>  $slotState
     * @return array{reply_text: string, handoff: bool, handoff_reason: string}
     */
    private function buildReply(
        ChatMessage $message,
        array $settings,
        Collection $rules,
        Collection $topics,
        ?ChatAiTopic $topic,
        Collection $products,
        Collection $selectedMedia,
        array $slotState,
        ?int $requestedSize,
        bool $isPhotoRequest,
        bool $isAllPhotosRequest,
        bool $shouldAskPhotoConfirmation,
        bool $shouldSendOverviewMedia
    ): array {
        $instructions = $this->buildSystemInstructions(
            $settings,
            $rules,
            $topics,
            $message,
            $slotState,
            $products,
            $requestedSize,
            $shouldAskPhotoConfirmation,
            $shouldSendOverviewMedia
        );
        $memory = $message->conversation
            ? $this->buildConversationMemoryBlock($message->conversation)
            : '';
        $history = $this->buildHistoryForPrompt(
            (int) $message->conversation_id,
            (int) ($settings['max_messages'] ?? 12)
        );
        $topicBlock = $this->buildTopicBlock(
            $topics,
            $topic,
            $products,
            $selectedMedia,
            $requestedSize,
            (string) ($message->text ?? ''),
            $isPhotoRequest,
            $isAllPhotosRequest,
            $shouldSendOverviewMedia
        );
        $slotBlock = $this->buildSlotStateBlock(
            $slotState['definitions'],
            $slotState['slots'],
            $slotState['missing'],
            $slotState['next'],
            $slotState['order_ready'],
            (bool) ($slotState['single_item_review_pending'] ?? false),
            (bool) ($slotState['multi_item_pending'] ?? false)
        );

        $input = implode("\n\n", array_filter([
            'Останнє повідомлення клієнта: ' . trim((string) ($message->text ?? '')),
            $memory,
            $slotBlock,
            $topicBlock,
            'Історія діалогу:',
            $history,
        ]));

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'reply_text' => [
                    'type' => 'string',
                    'description' => 'Текст, який AI має надіслати клієнту.',
                    'maxLength' => 1200,
                ],
                'handoff' => [
                    'type' => 'boolean',
                    'description' => 'true, якщо потрібно передати менеджеру.',
                ],
                'handoff_reason' => [
                    'type' => 'string',
                    'description' => 'Коротка причина handoff або порожньо.',
                    'maxLength' => 250,
                ],
            ],
            'required' => ['reply_text', 'handoff', 'handoff_reason'],
        ];

        $response = $this->openAi->createStructuredResponse(
            $instructions,
            $input,
            $schema,
            'chat_first_line_reply',
            trim((string) ($settings['model'] ?? '')) ?: null
        );

        $replyText = trim((string) ($response['reply_text'] ?? ''));
        if ($replyText === '') {
            throw new \RuntimeException('empty_reply_text');
        }

        return [
            'reply_text' => $replyText,
            'handoff' => (bool) ($response['handoff'] ?? false),
            'handoff_reason' => trim((string) ($response['handoff_reason'] ?? '')),
        ];
    }

    /**
     * @param  Collection<int, ChatAiResponseRule>  $rules
     */
    private function buildSystemInstructions(
        array $settings,
        Collection $rules,
        Collection $topics,
        ChatMessage $message,
        array $slotState,
        Collection $products,
        ?int $requestedSize,
        bool $shouldAskPhotoConfirmation,
        bool $shouldSendOverviewMedia
    ): string
    {
        $assistantName = trim((string) ($settings['assistant_name'] ?? 'DomCRM AI'));
        $replyStyle = trim((string) ($settings['reply_style'] ?? ''));
        $companyContext = trim((string) ($settings['company_context'] ?? ''));
        $knowledgeBase = trim((string) ($settings['knowledge_base'] ?? ''));
        $handoffRules = trim((string) ($settings['handoff_rules'] ?? ''));
        $qualificationFields = (array) ($settings['qualification_fields'] ?? []);
        $flowGuidance = $this->buildFlowGuidanceBlock(
            $topics,
            $message,
            $slotState,
            $products,
            $requestedSize,
            $shouldAskPhotoConfirmation,
            $shouldSendOverviewMedia
        );

        $rulesBlock = $rules
            ->map(function (ChatAiResponseRule $rule) {
                return "- {$rule->code}: {$rule->instruction}";
            })
            ->implode("\n");

        $qualificationText = collect($qualificationFields)
            ->map(fn ($field) => trim((string) $field))
            ->filter(fn ($field) => $field !== '')
            ->implode(', ');

        return trim(implode("\n\n", array_filter([
            "Ти {$assistantName}, перша лінія продажів у чаті магазину капців.",
            'Пиши тільки українською мовою, коротко та по суті.',
            'У відповіді клієнту не використовуй службове слово "тема". Використовуй: "модель", "варіант", "колір".',
            'Формуй відповідь тільки на основі переданого контексту з БД: правила, інструкція теми, товари, варіанти, медіа.',
            'Не вигадуй факти, ціни, розміри, наявність або умови доставки.',
            'Для конкретного товару фото треба брати з товарів, які прив’язані до моделі, а не вигадувати їх за назвою чи label.',
            'Колажі та палетки використовуй тільки для вибору моделі, коли модель ще не визначена.',
            'Наявність і доступні розміри визначай тільки за варіантами товару та їх залишками.',
            'Якщо даних недостатньо — прямо напиши про це і постав 1 уточнювальне питання.',
            'Фото надсилаються окремо системою. У тексті не вставляй URL.',
            'Якщо клієнт просить фото і в контексті медіа відсутні — коротко повідом, що немає підготовлених фото, і запропонуй близьку тему або менеджера.',
            'Якщо клієнт просить показати всі варіанти — у тексті підтвердь, що показуєш всі доступні для поточної теми.',
            'Якщо клієнт просить конкретний колір або варіант, а точних медіа в контексті немає, не пиши, що надсилаєш його фото.',
            'У блоці "Стан слотів замовлення" вже є зібрані поля. Не перепитуй те, що вже заповнено.',
            'Якщо система вказала "Наступний слот для уточнення", орієнтуйся на нього як на пріоритет і не перепитуй уже зібрані поля.',
            'Не став одне й те саме питання двічі поспіль. Якщо клієнт уже відповів, але система не змогла розібрати відповідь, коротко напиши, яке саме поле не вдалося зчитати, і попроси тільки його без повторення всього блоку питань.',
            'Зазвичай став одне коротке питання за раз, але на етапі оформлення можеш одним повідомленням попросити весь відсутній блок даних про доставку й отримувача.',
            'Коли перелічуєш розміри або розмірну сітку, пиши кожен варіант з нового рядка.',
            $replyStyle !== '' ? "Стиль відповіді: {$replyStyle}" : null,
            $companyContext !== '' ? "Контекст компанії: {$companyContext}" : null,
            $knowledgeBase !== '' ? "Додаткова база знань: {$knowledgeBase}" : null,
            $qualificationText !== '' ? "Поля кваліфікації, які треба зібрати по діалогу: {$qualificationText}" : null,
            $handoffRules !== '' ? "Коли передавати менеджеру:\n{$handoffRules}" : null,
            $flowGuidance !== '' ? "Додаткові правила поточного кроку:\n{$flowGuidance}" : null,
            $rulesBlock !== '' ? "Сценарні правила:\n{$rulesBlock}" : null,
        ])));
    }

    /**
     * @param  array<string, mixed>  $slotState
     * @param  Collection<int, array<string, mixed>>  $products
     */
    private function buildFlowGuidanceBlock(
        Collection $topics,
        ChatMessage $message,
        array $slotState,
        Collection $products,
        ?int $requestedSize,
        bool $shouldAskPhotoConfirmation,
        bool $shouldSendOverviewMedia
    ): string {
        $guidance = [];
        $text = (string) ($message->text ?? '');
        $nextSlot = is_string($slotState['next'] ?? null) ? $slotState['next'] : null;

        if ($nextSlot === 'model') {
            $availableModels = $this->buildAvailableTopicList($topics);
            $guidance[] = 'Модель треба визначити першою. Поки клієнт не вибрав модель, не переходь до кольору, розміру, кількості або оформлення.';

            if ($shouldSendOverviewMedia) {
                $guidance[] = 'Система окремо надсилає клієнту оглядові колажі всіх доступних моделей. У тексті не проси дивитися "тему" і не описуй технічну логіку, просто попроси обрати модель.';
            }

            if ($availableModels !== '') {
                $guidance[] = "Перелічи доступні моделі кожну з нового рядка:\n{$availableModels}";
            }
        }

        if ((bool) ($slotState['multi_item_pending'] ?? false)) {
            $guidance[] = 'Клієнт схоже збирає замовлення з кількох різних пар або змішує різні моделі. Не переходь до доставки й не проси місто, відділення чи адресу, поки не підтверджені всі позиції.';
            $guidance[] = 'Спочатку коротко перелічи позиції, як ти їх зрозумів, кожну з нового рядка, і попроси підтвердити або виправити список пар.';
            $guidance[] = 'Якщо для однієї з пар бракує лише одного поля, проси тільки його конкретно й просто, наприклад: "Уточніть, будь ласка, розмір для другої пари." Не використовуй фрази на кшталт "Тоді допишіть..." або канцелярський тон.';
            $guidance[] = 'Якщо клієнт уже явно написав кількість по кожній позиції, наприклад "одну пару чорних і одну пару сірих", включи цю кількість у список пар. Після підтвердження не перепитуй кількість або розмір цих самих пар ще раз.';
        }

        if ((bool) ($slotState['single_item_review_pending'] ?? false)) {
            $guidance[] = 'Для однієї пари теж потрібне коротке підтвердження перед оформленням. Не переходь до доставки й не проси місто, відділення чи адресу на цьому кроці.';
            $guidance[] = 'Коротко підсумуй одну позицію: модель, колір, розмір, кількість. Наприкінці попроси перевірити, чи правильно сформовано замовлення.';
        }

        if ((bool) ($slotState['single_item_just_confirmed'] ?? false)) {
            $guidance[] = 'Клієнт щойно підтвердив одну позицію. Не повторюй summary ще раз.';
            $guidance[] = 'Тепер можна перейти до оформлення і одним повідомленням попросити відсутні дані про доставку й отримувача.';
        }

        if ((bool) ($slotState['multi_item_just_confirmed'] ?? false)) {
            $guidance[] = 'Клієнт щойно підтвердив список кількох пар. Не повторюй цей список ще раз і не проси повторно підтвердження.';
            $guidance[] = 'Якщо хоча б для однієї пари ще не вистачає моделі, кольору або розміру, спочатку попроси саме ці деталі. До доставки переходь тільки коли всі позиції вже повністю зрозумілі.';
            $guidance[] = 'Проси відсутні деталі по другій або наступній парі коротко і по суті. Не пиши "Тоді допишіть..." і не використовуй сухі шаблонні формулювання.';
            $guidance[] = 'Лише коли всі пари вже описані повністю, одним наступним повідомленням попроси дані для оформлення замовлення: ПІБ отримувача, номер мобільного, місто або село, номер відділення чи поштомата або повну адресу для кур’єра.';
        }

        if ((bool) ($slotState['multi_item_review_completed'] ?? false)) {
            $guidance[] = 'Список кількох пар уже підтверджений клієнтом. Не повертайся знову до уточнення моделі, кольору, розміру чи кількості, якщо клієнт сам не змінює замовлення.';
            $guidance[] = 'Після підтвердженого списку пар переходь тільки до оформлення або до конкретного нового запиту клієнта. Не вигадуй, що для другої пари ще чогось бракує, якщо клієнт цього не написав.';
            $guidance[] = 'Вважай, що підтверджений список уже зафіксував модель, колір, розмір і кількість по кожній парі. Не проси повторно підтвердити розмір або кількість для вже підтверджених позицій.';
        }

        if ($shouldAskPhotoConfirmation) {
            $guidance[] = 'Клієнт схоже хоче переглянути товар, але не попросив фото прямо. Не вгадуй і не пиши, що фото немає. Одним коротким питанням уточни, чи показати фото цього варіанту.';
        }

        if ($requestedSize !== null && $this->isFootLengthConsultationText($text)) {
            $guidance[] = 'Клієнт назвав довжину стопи в сантиметрах або питає, чи не маломірить. Спочатку порадь відповідний розмір за сіткою, не переходь у цьому ж повідомленні до кількості.';
            $guidance[] = 'Після рекомендації спитай, чи підходить цей розмір. Попроси підтвердити саме розміром, наприклад: "Якщо підходить, напишіть, будь ласка, 36/37."';
        }

        if ($nextSlot === 'quantity') {
            $guidance[] = 'Коли уточнюєш кількість, пиши м’яко: "Скільки пар бажаєте замовити?" Не використовуй формулювання "кладемо".';
        }

        if ($this->shouldAskForOrderDetailsBundle($slotState)) {
            $guidance[] = $this->buildOrderDetailsBundleGuidance($slotState);
        } elseif ($nextSlot === 'city') {
            $guidance[] = 'Коли уточнюєш населений пункт, пиши: "Підкажіть, будь ласка, місто або населений пункт для доставки."';
        } elseif ($nextSlot === 'delivery') {
            $guidance[] = 'Коли уточнюєш доставку, пиши: "Підкажіть, будь ласка, що вам зручніше: Нова пошта, поштомат чи кур’єр?" Якщо потрібно, одразу попроси номер відділення, поштомата або адресу.';
        }

        if (in_array($nextSlot, ['city', 'delivery', 'customer_name', 'phone', 'payment'], true)) {
            $guidance[] = 'На етапі оформлення не повторюй один і той самий запит по 2-3 рази. Якщо клієнт уже надіслав дані одним повідомленням, використай усе, що вдалося розпізнати, і попроси тільки відсутнє.';
        }

        if ($products->isNotEmpty()) {
            $guidance[] = 'Якщо клієнт питає про наявні розміри, перелічи їх стовпчиком, кожен варіант з нового рядка.';
        }

        return implode("\n", array_filter($guidance));
    }

    /**
     * @param  array<string, mixed>  $slotState
     */
    private function shouldAskForOrderDetailsBundle(array $slotState): bool
    {
        $slots = is_array($slotState['slots'] ?? null) ? $slotState['slots'] : [];
        $missing = is_array($slotState['missing'] ?? null) ? $slotState['missing'] : [];
        $definitions = is_array($slotState['definitions'] ?? null) ? $slotState['definitions'] : [];

        if (
            $missing === []
            || (bool) ($slotState['multi_item_pending'] ?? false)
            || (bool) ($slotState['single_item_review_pending'] ?? false)
        ) {
            return false;
        }

        if (
            !$this->hasSlotValue($slots['model'] ?? null, 'model')
            || ((bool) data_get($definitions, 'color.required', false) && !$this->hasSlotValue($slots['color'] ?? null, 'color'))
            || !$this->hasSlotValue($slots['size'] ?? null, 'size')
        ) {
            return false;
        }

        if (!$this->hasSlotValue($slots['quantity'] ?? null, 'quantity')) {
            return false;
        }

        return collect(['customer_name', 'phone', 'city', 'delivery'])
            ->contains(fn (string $key) => in_array($key, $missing, true));
    }

    /**
     * @param  array<string, array<string, mixed>>  $definitions
     * @param  array<string, mixed>  $slots
     * @param  array<int, string>  $missing
     */
    private function shouldRequireSingleItemReview(array $definitions, array $slots, array $missing): bool
    {
        if (!$this->hasSlotValue($slots['model'] ?? null, 'model')) {
            return false;
        }

        if (
            (bool) data_get($definitions, 'color.required', false)
            && !$this->hasSlotValue($slots['color'] ?? null, 'color')
        ) {
            return false;
        }

        if (
            !$this->hasSlotValue($slots['size'] ?? null, 'size')
            || !$this->hasSlotValue($slots['quantity'] ?? null, 'quantity')
        ) {
            return false;
        }

        return collect(['customer_name', 'phone', 'city', 'delivery'])
            ->contains(fn (string $key) => in_array($key, $missing, true));
    }

    /**
     * @param  array<string, mixed>  $slotState
     */
    private function buildOrderDetailsBundleGuidance(array $slotState): string
    {
        $missing = array_values(array_filter(
            is_array($slotState['missing'] ?? null) ? $slotState['missing'] : [],
            fn (string $key) => in_array($key, ['customer_name', 'phone', 'city', 'delivery'], true)
        ));

        $fieldLines = [];

        if (in_array('customer_name', $missing, true)) {
            $fieldLines[] = '- ПІБ отримувача';
        }
        if (in_array('phone', $missing, true)) {
            $fieldLines[] = '- Номер мобільного';
        }
        if (in_array('city', $missing, true)) {
            $fieldLines[] = '- Місто або населений пункт';
        }
        if (in_array('delivery', $missing, true)) {
            $fieldLines[] = '- Що зручніше: Нова пошта, поштомат чи кур’єр';
            $fieldLines[] = '- Номер відділення, поштомата або повна адреса для кур’єра';
        }

        $template = implode("\n", array_filter([
            'Ми вже на етапі оформлення. Замість окремих коротких питань попроси клієнта одним повідомленням надіслати відсутні дані.',
            'Сформулюй повідомлення у такому стилі:',
            'Для оформлення замовлення вкажіть, будь ласка, такі дані:',
            implode("\n", $fieldLines),
            'Якщо частина даних уже зібрана, не дублюй їх і попроси лише те, чого не вистачає.',
        ]));

        return $template;
    }

    private function buildHistoryForPrompt(int $conversationId, int $maxMessages): string
    {
        $history = ChatMessage::query()
            ->with('attachments')
            ->where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->limit(max(8, min(60, $maxMessages * 2)))
            ->get()
            ->reverse()
            ->values();

        return $history
            ->map(function (ChatMessage $message) {
                $role = match ($message->direction) {
                    'inbound' => 'Клієнт',
                    default => $message->source === 'operator' ? 'Менеджер' : 'AI',
                };

                $text = trim((string) ($message->text ?? ''));
                if ($text === '') {
                    $text = $message->attachments->isNotEmpty() ? '[Вкладення]' : '[Порожньо]';
                }

                return "{$role}: {$text}";
            })
            ->implode("\n");
    }

    private function buildConversationMemoryBlock(ChatConversation $conversation): string
    {
        $ai = is_array(data_get($conversation->meta, 'ai'))
            ? data_get($conversation->meta, 'ai')
            : [];

        $lines = [];
        $orderDraftSummary = trim((string) data_get($ai, 'order_draft.summary', ''));

        if ($orderDraftSummary !== '') {
            $lines[] = "Чернетка замовлення:\n{$orderDraftSummary}";
        }

        $slotSummary = trim((string) ($ai['slot_summary'] ?? ''));
        if ($slotSummary !== '' && $slotSummary !== $orderDraftSummary) {
            $lines[] = $slotSummary;
        }

        $lastTopicName = trim((string) ($ai['last_topic_name'] ?? ''));
        if ($lastTopicName !== '' && !str_contains($slotSummary, $lastTopicName)) {
            $lines[] = "Остання визначена тема: {$lastTopicName}";
        }

        $lastRequestedSize = isset($ai['last_requested_size']) ? (int) $ai['last_requested_size'] : null;
        if (
            $lastRequestedSize !== null
            && $lastRequestedSize >= 20
            && $lastRequestedSize <= 55
            && !str_contains($slotSummary, (string) $lastRequestedSize)
        ) {
            $lines[] = "Остання згадана довжина стопи або розмір: {$lastRequestedSize}";
        }

        if ($lines === []) {
            return '';
        }

        return "Коротка пам'ять діалогу:\n" . implode("\n", $lines);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $products
     * @param  array<string, mixed>  $slotState
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     summary: string,
     *     source: string,
     *     confidence: ?float
     * }
     */
    private function buildConversationOrderDraft(
        ChatConversation $conversation,
        ChatMessage $message,
        ?ChatAiTopic $topic,
        Collection $products,
        array $slotState,
        array $settings
    ): array {
        $previousDraft = $this->loadStoredOrderDraft($conversation);
        $fallbackDraft = $this->buildFallbackOrderDraft($slotState, $previousDraft);

        if (!$this->shouldUseAiOrderDraftExtractor($message, $slotState, $previousDraft)) {
            return $fallbackDraft;
        }

        try {
            $extractedDraft = $this->extractOrderDraftWithAi(
                $conversation,
                $message,
                $topic,
                $products,
                $slotState,
                $previousDraft,
                $settings
            );

            if ($extractedDraft !== null) {
                return $extractedDraft;
            }
        } catch (\Throwable $e) {
            Log::warning('AI: не вдалося зібрати structured order draft', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $fallbackDraft;
    }

    /**
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     summary: string,
     *     source: string,
     *     confidence: ?float
     * }
     */
    private function loadStoredOrderDraft(ChatConversation $conversation): array
    {
        $items = $this->normalizeOrderDraftItems(data_get($conversation->meta, 'ai.order_draft.items'));
        $summary = trim((string) data_get($conversation->meta, 'ai.order_draft.summary', ''));
        $source = trim((string) data_get($conversation->meta, 'ai.order_draft.source', ''));
        $confidence = data_get($conversation->meta, 'ai.order_draft.confidence');

        return [
            'items' => $items,
            'summary' => $summary !== '' ? $summary : $this->buildOrderDraftSummary($items),
            'source' => $source !== '' ? $source : 'stored',
            'confidence' => is_numeric($confidence) ? round((float) $confidence, 3) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $slotState
     * @param  array{
     *     items: array<int, array<string, mixed>>,
     *     summary: string,
     *     source: string,
     *     confidence: ?float
     * }  $previousDraft
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     summary: string,
     *     source: string,
     *     confidence: ?float
     * }
     */
    private function buildFallbackOrderDraft(array $slotState, array $previousDraft): array
    {
        $items = [];
        $shouldKeepPreviousItems = (bool) ($slotState['multi_item_pending'] ?? false)
            || (bool) ($slotState['multi_item_review_completed'] ?? false)
            || count($previousDraft['items'] ?? []) > 1;

        if ($shouldKeepPreviousItems && !empty($previousDraft['items'])) {
            $items = $previousDraft['items'];
        } else {
            $slots = is_array($slotState['slots'] ?? null) ? $slotState['slots'] : [];
            $singleItem = $this->buildSingleOrderDraftItemFromSlots($slots);

            if ($singleItem !== null) {
                $items = [$singleItem];
            } elseif (!empty($previousDraft['items'])) {
                $items = $previousDraft['items'];
            }
        }

        return [
            'items' => $items,
            'summary' => $this->buildOrderDraftSummary($items),
            'source' => !empty($items) && $shouldKeepPreviousItems ? 'stored_fallback' : 'slots_fallback',
            'confidence' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $slotState
     * @param  array{
     *     items: array<int, array<string, mixed>>,
     *     summary: string,
     *     source: string,
     *     confidence: ?float
     * }  $previousDraft
     */
    private function shouldUseAiOrderDraftExtractor(
        ChatMessage $message,
        array $slotState,
        array $previousDraft
    ): bool {
        $text = trim((string) ($message->text ?? ''));
        if ($text === '') {
            return false;
        }

        if (!empty($previousDraft['items'])) {
            return true;
        }

        if ((bool) ($slotState['multi_item_pending'] ?? false) || (bool) ($slotState['multi_item_review_completed'] ?? false)) {
            return true;
        }

        $slots = is_array($slotState['slots'] ?? null) ? $slotState['slots'] : [];

        foreach (['model', 'color', 'size', 'quantity'] as $key) {
            if ($this->hasSlotValue($slots[$key] ?? null, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $products
     * @param  array<string, mixed>  $slotState
     * @param  array{
     *     items: array<int, array<string, mixed>>,
     *     summary: string,
     *     source: string,
     *     confidence: ?float
     * }  $previousDraft
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     summary: string,
     *     source: string,
     *     confidence: ?float
     * }|null
     */
    private function extractOrderDraftWithAi(
        ChatConversation $conversation,
        ChatMessage $message,
        ?ChatAiTopic $topic,
        Collection $products,
        array $slotState,
        array $previousDraft,
        array $settings
    ): ?array {
        $slots = is_array($slotState['slots'] ?? null) ? $slotState['slots'] : [];
        $currentModel = $this->normalizeSlotValue('model', $slots['model'] ?? ($topic?->name ?? null));
        $currentColor = $this->normalizeSlotValue('color', $slots['color'] ?? null);
        $currentSize = $this->normalizeSlotValue('size', $slots['size'] ?? null);
        $currentQuantity = $this->normalizeSlotValue('quantity', $slots['quantity'] ?? null);
        $previousItemsJson = json_encode($previousDraft['items'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $history = $this->buildHistoryForPrompt($conversation->id, 12);
        $memory = $this->buildConversationMemoryBlock($conversation);
        $productHints = $products
            ->map(function (array $product) {
                $parts = array_filter([
                    trim((string) ($product['title'] ?? '')),
                    trim((string) ($product['color_name'] ?? '')),
                ]);

                return $parts !== [] ? '- ' . implode(' | ', $parts) : null;
            })
            ->filter()
            ->take(12)
            ->implode("\n");

        $input = implode("\n\n", array_filter([
            'Останнє повідомлення клієнта: ' . trim((string) ($message->text ?? '')),
            $memory,
            'Поточні слоти:'
                . ($currentModel !== null ? "\n- модель: {$currentModel}" : "\n- модель: не визначена")
                . ($currentColor !== null ? "\n- колір: {$currentColor}" : '')
                . ($currentSize !== null ? "\n- розмір: {$currentSize}" : '')
                . ($currentQuantity !== null ? "\n- кількість: {$currentQuantity}" : ''),
            $previousItemsJson !== false ? "Попередня чернетка позицій JSON: {$previousItemsJson}" : null,
            $productHints !== '' ? "Підказки по товарах поточної моделі:\n{$productHints}" : null,
            "Останні повідомлення діалогу:\n{$history}",
        ]));

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'model' => ['type' => 'string'],
                            'color' => ['type' => 'string'],
                            'size' => ['type' => 'string'],
                            'quantity' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20],
                        ],
                        'required' => ['model', 'color', 'size', 'quantity'],
                    ],
                ],
                'summary' => ['type' => 'string'],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'reason' => ['type' => 'string'],
            ],
            'required' => ['items', 'summary', 'confidence', 'reason'],
        ];

        $instructions = implode("\n", [
            'Ти витягуєш структуровану чернетку замовлення для внутрішньої CRM-панелі.',
            'Поверни повний поточний список позицій замовлення, які вже можна зрозуміти з діалогу.',
            'Якщо клієнт уже підтвердив список кількох пар, збережи весь список, а не лише останню пару.',
            'Якщо повідомлення стосується доставки, оплати, імені чи телефону, але список позицій уже був зрозумілий раніше, збережи попередні позиції без змін.',
            'Якщо позиція неповна, залиш порожній рядок для невідомого поля.',
            'Кількість став 1, якщо клієнт явно описує окрему пару без іншої кількості, наприклад "одну пару чорних і одну пару сірих".',
            'Не вигадуй позиції, яких не було в діалозі.',
            'Поверни summary коротко: кожна позиція з нового рядка, без службового тексту.',
        ]);

        $response = $this->openAi->createStructuredResponse(
            $instructions,
            $input,
            $schema,
            'chat_order_draft_extractor',
            (string) ($settings['model'] ?? '')
        );

        $confidence = isset($response['confidence']) ? (float) $response['confidence'] : 0.0;
        if ($confidence < 0.55) {
            return null;
        }

        $items = $this->normalizeOrderDraftItems($response['items'] ?? null);
        if ($items === [] && !empty($previousDraft['items'])) {
            $items = $previousDraft['items'];
        }

        $summary = trim((string) ($response['summary'] ?? ''));

        return [
            'items' => $items,
            'summary' => $summary !== '' ? $summary : $this->buildOrderDraftSummary($items),
            'source' => 'ai_extractor',
            'confidence' => round($confidence, 3),
        ];
    }

    /**
     * @param  mixed  $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeOrderDraftItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];

        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $model = $this->normalizeSlotValue('model', $item['model'] ?? null);
            $color = $this->normalizeSlotValue('color', $item['color'] ?? null);
            $size = $this->normalizeSlotValue('size', $item['size'] ?? null);
            $quantity = $this->normalizeOrderDraftQuantity($item['quantity'] ?? null);

            if ($model === null && $color === null && $size === null && $quantity === null) {
                continue;
            }

            $normalized[] = [
                'id' => 'item-' . ($index + 1),
                'model' => $model,
                'color' => $color,
                'size' => $size,
                'quantity' => $quantity,
            ];
        }

        return $normalized;
    }

    private function normalizeOrderDraftQuantity(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        $quantity = (int) $value;

        return $quantity >= 1 && $quantity <= 20 ? $quantity : null;
    }

    /**
     * @param  array<string, mixed>  $slots
     * @return array<string, mixed>|null
     */
    private function buildSingleOrderDraftItemFromSlots(array $slots): ?array
    {
        $model = $this->normalizeSlotValue('model', $slots['model'] ?? null);
        $color = $this->normalizeSlotValue('color', $slots['color'] ?? null);
        $size = $this->normalizeSlotValue('size', $slots['size'] ?? null);
        $quantity = $this->normalizeOrderDraftQuantity($slots['quantity'] ?? null);

        if ($model === null && $color === null && $size === null && $quantity === null) {
            return null;
        }

        return [
            'id' => 'item-1',
            'model' => $model,
            'color' => $color,
            'size' => $size,
            'quantity' => $quantity,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function buildOrderDraftSummary(array $items): string
    {
        if ($items === []) {
            return '';
        }

        $lines = [];

        foreach ($items as $item) {
            $parts = array_filter([
                $item['model'] ?? null,
                $item['color'] ?? null,
                $item['size'] ?? null,
                isset($item['quantity']) && (int) $item['quantity'] > 1 ? ((int) $item['quantity']) . ' шт.' : null,
            ], fn ($value) => is_string($value) ? trim($value) !== '' : $value !== null);

            if ($parts === []) {
                continue;
            }

            $lines[] = '- ' . implode(', ', array_map(
                fn ($value) => is_string($value) ? trim($value) : (string) $value,
                $parts
            ));
        }

        return implode("\n", $lines);
    }

    private function isAwaitingPhotoConfirmation(ChatConversation $conversation): bool
    {
        return (bool) data_get($conversation->meta, 'ai.awaiting_photo_confirmation', false);
    }

    /**
     * @param  array<string, mixed>  $slotState
     */
    private function hasResolvedVisualContext(?ChatAiTopic $topic, array $slotState, ChatConversation $conversation): bool
    {
        $slots = is_array($slotState['slots'] ?? null) ? $slotState['slots'] : [];

        if ($topic !== null) {
            return true;
        }

        if (
            $this->hasSlotValue($slots['model'] ?? null, 'model')
            || $this->hasSlotValue($slots['color'] ?? null, 'color')
        ) {
            return true;
        }

        return trim((string) data_get($conversation->meta, 'ai.last_topic_name', '')) !== '';
    }

    /**
     * @param  array<string, mixed>  $slotState
     */
    private function hasSelectedModelContext(?ChatAiTopic $topic, array $slotState, ChatConversation $conversation): bool
    {
        if ($topic !== null) {
            return true;
        }

        $slots = is_array($slotState['slots'] ?? null) ? $slotState['slots'] : [];
        if ($this->hasSlotValue($slots['model'] ?? null, 'model')) {
            return true;
        }

        return (int) data_get($conversation->meta, 'ai.last_topic_id', 0) > 0;
    }

    /**
     * @param  array<string, mixed>  $slotState
     */
    private function resolveCurrentModelContextName(?ChatAiTopic $topic, array $slotState, ChatConversation $conversation): ?string
    {
        if ($topic !== null && trim((string) $topic->name) !== '') {
            return trim((string) $topic->name);
        }

        $model = $this->normalizeSlotValue('model', data_get($slotState, 'slots.model'));
        if (is_string($model) && trim($model) !== '') {
            return trim($model);
        }

        $lastTopicName = trim((string) data_get($conversation->meta, 'ai.last_topic_name', ''));

        return $lastTopicName !== '' ? $lastTopicName : null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $products
     * @return array{
     *     definitions: array<string, array<string, mixed>>,
     *     slots: array<string, mixed>,
     *     missing: array<int, string>,
     *     next: ?string,
     *     order_ready: bool,
     *     summary: string,
     *     updated: array<string, mixed>,
     *     updated_keys: array<int, string>,
     *     just_completed: bool,
     *     single_item_review_pending: bool,
     *     single_item_review_completed: bool,
     *     single_item_just_confirmed: bool,
     *     multi_item_pending: bool,
     *     multi_item_review_completed: bool,
     *     multi_item_just_confirmed: bool
     * }
     */
    private function buildConversationSlotState(
        ChatConversation $conversation,
        ChatMessage $message,
        array $settings,
        ?ChatAiTopic $topic,
        Collection $products,
        int $topicScore,
        ?int $requestedSize
    ): array {
        $definitions = $this->buildRequiredSlotDefinitions($settings);
        $slots = $this->hydrateBaseSlotValues($this->loadStoredSlotValues($conversation), $conversation);
        $previousMissing = $this->resolveMissingSlotKeys($definitions, $slots);
        $previousOrderReady = (bool) data_get($conversation->meta, 'ai.order_ready', false);
        $previousMultiItemPending = (bool) data_get($conversation->meta, 'ai.multi_item_pending', false);
        $previousMultiItemReviewCompleted = (bool) data_get($conversation->meta, 'ai.multi_item_review_completed', false);
        $previousSingleItemReviewPending = (bool) data_get($conversation->meta, 'ai.single_item_review_pending', false);
        $previousSingleItemReviewCompleted = (bool) data_get($conversation->meta, 'ai.single_item_review_completed', false);
        $previousNextSlot = data_get($conversation->meta, 'ai.next_slot');
        $text = (string) ($message->text ?? '');
        $orderIntent = $this->resolveOrderIntent(
            $conversation,
            $message,
            $topic,
            $products,
            $slots,
            $previousNextSlot,
            $previousMultiItemPending,
            $settings
        );
        $currentMultiItemPending = in_array((string) ($orderIntent['intent'] ?? ''), ['multi_item_add', 'multi_item_edit'], true)
            || $this->isComplexMultiItemOrderText($text);
        $multiItemJustConfirmed = $previousMultiItemPending
            && (
                (string) ($orderIntent['intent'] ?? '') === 'multi_item_confirm'
                || (!$currentMultiItemPending && $this->isAffirmativeReply($text))
            );

        if (!is_string($previousNextSlot) || !array_key_exists($previousNextSlot, $definitions)) {
            $previousNextSlot = $previousMissing[0] ?? null;
        }

        $candidateUpdates = $this->extractSlotUpdates(
            $conversation,
            $message,
            $topic,
            $products,
            $topicScore,
            $requestedSize,
            $slots,
            $previousNextSlot,
            (string) ($orderIntent['intent'] ?? 'none')
        );

        $updated = [];

        foreach ($candidateUpdates as $key => $value) {
            if (!array_key_exists($key, $definitions)) {
                continue;
            }

            $normalizedValue = $this->normalizeSlotValue($key, $value);
            if ($normalizedValue === null) {
                continue;
            }

            if ($this->slotValuesEqual($key, $slots[$key] ?? null, $normalizedValue)) {
                continue;
            }

            $slots[$key] = $normalizedValue;
            $updated[$key] = $normalizedValue;
        }

        $missing = $this->resolveMissingSlotKeys($definitions, $slots);
        $itemDefinitionChanged = collect(['model', 'color', 'size', 'quantity'])
            ->contains(fn (string $key) => array_key_exists($key, $updated));
        $multiItemReviewCompleted = $multiItemJustConfirmed
            ? true
            : (
                ($currentMultiItemPending || $itemDefinitionChanged)
                    ? false
                    : $previousMultiItemReviewCompleted
            );
        $multiItemPending = !$multiItemReviewCompleted
            && (
                $currentMultiItemPending
                || ($previousMultiItemPending && !$multiItemJustConfirmed && !$this->isSingleItemResetText($text))
            );
        if ($multiItemReviewCompleted) {
            $missing = array_values(array_filter(
                $missing,
                fn (string $key) => in_array($key, ['customer_name', 'phone', 'city', 'delivery', 'payment'], true)
            ));
        }
        $singleItemReviewJustConfirmed = $previousSingleItemReviewPending
            && !$multiItemPending
            && !$multiItemReviewCompleted
            && $this->isAffirmativeReply($text);
        $singleItemReviewCompleted = $singleItemReviewJustConfirmed
            ? true
            : ($itemDefinitionChanged ? false : $previousSingleItemReviewCompleted);
        $singleItemReviewPending = !$multiItemPending
            && !$multiItemReviewCompleted
            && !$singleItemReviewCompleted
            && !$singleItemReviewJustConfirmed
            && $this->shouldRequireSingleItemReview($definitions, $slots, $missing);
        $nextSlot = $singleItemReviewPending ? null : ($missing[0] ?? null);
        $orderReady = $missing === [];

        return [
            'definitions' => $definitions,
            'slots' => $slots,
            'missing' => $missing,
            'next' => $nextSlot,
            'order_ready' => $orderReady,
            'summary' => $this->buildSlotSummary(
                $definitions,
                $slots,
                $missing,
                $nextSlot,
                $orderReady,
                $singleItemReviewPending,
                $multiItemPending
            ),
            'updated' => $updated,
            'updated_keys' => array_keys($updated),
            'just_completed' => !$previousOrderReady && $orderReady,
            'order_intent' => (string) ($orderIntent['intent'] ?? 'none'),
            'order_intent_source' => $orderIntent['source'] ?? null,
            'order_intent_reason' => $orderIntent['reason'] ?? null,
            'order_intent_confidence' => $orderIntent['confidence'] ?? null,
            'single_item_review_pending' => $singleItemReviewPending,
            'single_item_review_completed' => $singleItemReviewCompleted,
            'single_item_just_confirmed' => $singleItemReviewJustConfirmed,
            'multi_item_pending' => $multiItemPending,
            'multi_item_review_completed' => $multiItemReviewCompleted,
            'multi_item_just_confirmed' => $multiItemJustConfirmed,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildRequiredSlotDefinitions(array $settings): array
    {
        $baseDefinitions = [
            'model' => ['key' => 'model', 'label' => 'Модель', 'required' => true],
            'color' => ['key' => 'color', 'label' => 'Колір', 'required' => false],
            'size' => ['key' => 'size', 'label' => 'Розмір', 'required' => true],
            'quantity' => ['key' => 'quantity', 'label' => 'Кількість', 'required' => false],
            'city' => ['key' => 'city', 'label' => 'Місто / населений пункт', 'required' => true],
            'delivery' => ['key' => 'delivery', 'label' => 'Спосіб доставки / відділення / адреса', 'required' => true],
            'customer_name' => ['key' => 'customer_name', 'label' => "Ім'я", 'required' => true],
            'phone' => ['key' => 'phone', 'label' => 'Телефон', 'required' => true],
            'payment' => ['key' => 'payment', 'label' => 'Оплата', 'required' => false],
        ];
        $orderedKeys = [];

        foreach ((array) ($settings['qualification_fields'] ?? []) as $field) {
            $slotKey = $this->mapQualificationFieldToSlot((string) $field);
            if ($slotKey === null || !array_key_exists($slotKey, $baseDefinitions)) {
                continue;
            }

            $baseDefinitions[$slotKey]['required'] = true;

            if (!in_array($slotKey, $orderedKeys, true)) {
                $orderedKeys[] = $slotKey;
            }
        }

        $definitions = [];

        foreach ($orderedKeys as $slotKey) {
            $definitions[$slotKey] = $baseDefinitions[$slotKey];
        }

        foreach ($baseDefinitions as $slotKey => $definition) {
            if (array_key_exists($slotKey, $definitions)) {
                continue;
            }

            $definitions[$slotKey] = $definition;
        }

        return $definitions;
    }

    private function mapQualificationFieldToSlot(string $field): ?string
    {
        $normalized = $this->normalizeText($field);

        return match (true) {
            (bool) preg_match('/\b(товар|модел[ьяі]|продукт|варіант)\b/u', $normalized) => 'model',
            (bool) preg_match('/\b(колір|цвет)\b/u', $normalized) => 'color',
            (bool) preg_match('/\b(розмір|розм|size)\b/u', $normalized) => 'size',
            (bool) preg_match('/\b(кількість|кільк|qty|пара|пар)\b/u', $normalized) => 'quantity',
            (bool) preg_match('/\b(місто|город|село|смт|населен)\b/u', $normalized) => 'city',
            (bool) preg_match('/\b(відділення|відділ|доставка|адреса|адрес|поштомат)\b/u', $normalized) => 'delivery',
            (bool) preg_match('/\b(ім[\'’`ʼ]?я|имя|прізвище|отримувач)\b/u', $normalized) => 'customer_name',
            (bool) preg_match('/\b(телефон|тел|номер)\b/u', $normalized) => 'phone',
            (bool) preg_match('/\b(оплата|післяплата|карта|передоплата)\b/u', $normalized) => 'payment',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function loadStoredSlotValues(ChatConversation $conversation): array
    {
        $stored = data_get($conversation->meta, 'ai.slot_values');
        if (!is_array($stored)) {
            return [];
        }

        $result = [];

        foreach ($stored as $key => $value) {
            if (!is_string($key) || is_array($value) || is_object($value)) {
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $slots
     * @return array<string, mixed>
     */
    private function hydrateBaseSlotValues(array $slots, ChatConversation $conversation): array
    {
        $customer = $conversation->customer;
        $ai = is_array(data_get($conversation->meta, 'ai'))
            ? data_get($conversation->meta, 'ai')
            : [];

        $fullName = trim(implode(' ', array_filter([
            trim((string) ($customer?->first_name ?? '')),
            trim((string) ($customer?->last_name ?? '')),
        ])));

        if ($fullName !== '' && !$this->hasSlotValue($slots['customer_name'] ?? null, 'customer_name')) {
            $slots['customer_name'] = $fullName;
        }

        $phone = $this->normalizeSlotValue('phone', (string) ($customer?->phone ?? ''));
        if ($phone !== null && !$this->hasSlotValue($slots['phone'] ?? null, 'phone')) {
            $slots['phone'] = $phone;
        }

        foreach ($slots as $key => $value) {
            $normalized = $this->normalizeSlotValue((string) $key, $value);
            if ($normalized === null) {
                unset($slots[$key]);
                continue;
            }

            $slots[$key] = $normalized;
        }

        return $slots;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $products
     * @param  array<string, mixed>  $currentSlots
     * @return array<string, mixed>
     */
    private function extractSlotUpdates(
        ChatConversation $conversation,
        ChatMessage $message,
        ?ChatAiTopic $topic,
        Collection $products,
        int $topicScore,
        ?int $requestedSize,
        array $currentSlots,
        ?string $previousNextSlot,
        string $orderIntent = 'none'
    ): array {
        $text = trim((string) ($message->text ?? ''));
        if ($text === '') {
            return [];
        }

        $updates = [];

        $bundledDelivery = $this->extractBundledDeliveryDetails($text, $previousNextSlot);
        if ($bundledDelivery['city'] !== null) {
            $updates['city'] = $bundledDelivery['city'];
        }
        if ($bundledDelivery['delivery'] !== null) {
            $updates['delivery'] = $bundledDelivery['delivery'];
        }

        if ($topic !== null && ($topicScore > 0 || $products->isNotEmpty() || !$this->hasSlotValue($currentSlots['model'] ?? null, 'model'))) {
            $updates['model'] = $topic->name;
        }

        if ($requestedSize !== null && $this->shouldPersistRequestedSizeAsSlot($text, $previousNextSlot)) {
            $resolvedSize = $this->resolveRequestedSizeSlotValue($text, $products, $requestedSize);
            if ($resolvedSize !== null) {
                $updates['size'] = $resolvedSize;
            }
        }

        if ($color = $this->extractColorValue($text, $previousNextSlot)) {
            $updates['color'] = $color;
        }

        if (
            !in_array($orderIntent, ['multi_item_add', 'multi_item_edit', 'multi_item_confirm'], true)
            && ($quantity = $this->extractQuantityValue($text, $previousNextSlot))
        ) {
            $updates['quantity'] = $quantity;
        }

        if (!array_key_exists('city', $updates) && ($city = $this->extractCityValue($text, $previousNextSlot))) {
            $updates['city'] = $city;
        }

        if (!array_key_exists('delivery', $updates) && ($delivery = $this->extractDeliveryValue($text, $previousNextSlot))) {
            $updates['delivery'] = $delivery;
        }

        if ($phone = $this->extractPhoneValue($text)) {
            $updates['phone'] = $phone;
        }

        if ($customerName = $this->extractCustomerNameValue($text, $previousNextSlot, $conversation)) {
            $updates['customer_name'] = $customerName;
        }

        if ($payment = $this->extractPaymentValue($text)) {
            $updates['payment'] = $payment;
        }

        return $updates;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $products
     * @param  array<string, mixed>  $slots
     * @return array{
     *     intent: string,
     *     source: string,
     *     reason: ?string,
     *     confidence: ?float
     * }
     */
    private function resolveOrderIntent(
        ChatConversation $conversation,
        ChatMessage $message,
        ?ChatAiTopic $topic,
        Collection $products,
        array $slots,
        ?string $previousNextSlot,
        bool $previousMultiItemPending,
        array $settings
    ): array {
        $fallback = $this->resolveOrderIntentFallback(
            (string) ($message->text ?? ''),
            $previousNextSlot,
            $previousMultiItemPending
        );

        if (!$this->shouldUseAiOrderIntentClassifier(
            (string) ($message->text ?? ''),
            $previousNextSlot,
            $previousMultiItemPending,
            $slots
        )) {
            return $fallback;
        }

        try {
            $aiIntent = $this->classifyOrderIntentWithAi(
                $conversation,
                $message,
                $topic,
                $products,
                $slots,
                $previousNextSlot,
                $previousMultiItemPending,
                $settings
            );
            if ($aiIntent !== null) {
                return $aiIntent;
            }
        } catch (\Throwable $e) {
            Log::warning('AI: не вдалося визначити order-intent через classifier', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    private function shouldUseAiOrderIntentClassifier(
        string $text,
        ?string $previousNextSlot,
        bool $previousMultiItemPending,
        array $slots
    ): bool {
        $normalized = $this->normalizeText($text);
        if ($normalized === '') {
            return false;
        }

        if ($previousMultiItemPending) {
            return true;
        }

        if ($previousNextSlot === 'quantity') {
            return true;
        }

        if ($this->hasSlotValue($slots['quantity'] ?? null, 'quantity')) {
            return true;
        }

        return $this->hasAdditionalPairIntentCue($normalized);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $products
     * @param  array<string, mixed>  $slots
     * @return array{
     *     intent: string,
     *     source: string,
     *     reason: ?string,
     *     confidence: ?float
     * }|null
     */
    private function classifyOrderIntentWithAi(
        ChatConversation $conversation,
        ChatMessage $message,
        ?ChatAiTopic $topic,
        Collection $products,
        array $slots,
        ?string $previousNextSlot,
        bool $previousMultiItemPending,
        array $settings
    ): ?array {
        $currentModel = $this->normalizeSlotValue('model', $slots['model'] ?? ($topic?->name ?? null));
        $currentColor = $this->normalizeSlotValue('color', $slots['color'] ?? null);
        $currentSize = $this->normalizeSlotValue('size', $slots['size'] ?? null);
        $currentQuantity = $this->normalizeSlotValue('quantity', $slots['quantity'] ?? null);
        $availableColors = $products
            ->pluck('color_name')
            ->map(fn ($value) => $this->normalizeSlotValue('color', $value))
            ->filter()
            ->unique()
            ->values()
            ->implode(', ');
        $availableSizes = $products
            ->flatMap(fn (array $product) => (array) ($product['available_sizes'] ?? []))
            ->map(fn ($value) => $this->normalizeSlotValue('size', $value))
            ->filter()
            ->unique()
            ->values()
            ->implode(', ');
        $memory = $this->buildConversationMemoryBlock($conversation);
        $history = $this->buildHistoryForPrompt($conversation->id, 8);

        $input = implode("\n\n", array_filter([
            'Останнє повідомлення клієнта: ' . trim((string) ($message->text ?? '')),
            $memory,
            'Поточний стан замовлення:'
                . ($currentModel !== null ? "\n- модель: {$currentModel}" : "\n- модель: не визначена")
                . ($currentColor !== null ? "\n- колір: {$currentColor}" : '')
                . ($currentSize !== null ? "\n- розмір: {$currentSize}" : '')
                . ($currentQuantity !== null ? "\n- кількість: {$currentQuantity}" : '')
                . ($previousNextSlot !== null ? "\n- попередній наступний слот: {$previousNextSlot}" : '')
                . "\n- multi_item_pending: " . ($previousMultiItemPending ? 'так' : 'ні'),
            $availableColors !== '' ? "Доступні кольори поточної моделі: {$availableColors}" : null,
            $availableSizes !== '' ? "Доступні розміри поточної моделі: {$availableSizes}" : null,
            "Останні повідомлення діалогу:\n{$history}",
        ]));

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'intent' => [
                    'type' => 'string',
                    'enum' => [
                        'none',
                        'single_item_quantity',
                        'multi_item_add',
                        'multi_item_edit',
                        'multi_item_confirm',
                    ],
                ],
                'confidence' => [
                    'type' => 'number',
                    'minimum' => 0,
                    'maximum' => 1,
                ],
                'reason' => [
                    'type' => 'string',
                ],
            ],
            'required' => ['intent', 'confidence', 'reason'],
        ];

        $instructions = implode("\n", [
            'Ти визначаєш намір клієнта щодо складу замовлення.',
            'Поверни тільки один intent із дозволеного списку.',
            'single_item_quantity — клієнт просто називає кількість для вже вибраного одного товару.',
            'multi_item_add — клієнт додає ще одну пару, каже про себе і ще когось, згадує другу людину, ще одну пару, інший колір чи розмір для другої пари.',
            'multi_item_edit — клієнт змінює вже озвучений список кількох пар.',
            'multi_item_confirm — клієнт підтверджує, що список кількох пар правильний.',
            'none — якщо повідомлення не стосується кількості чи складу кількох пар.',
            'Фрази на кшталт "собі і мамі", "нам дві", "ще одну пару", "одні мені, одні мамі" трактуй як multi_item_add, а не як просту кількість одного товару.',
            'Якщо клієнт підтверджує список кількох пар словами "так", "так все правильно", "все вірно" — це multi_item_confirm.',
            'Не вигадуй деталі другої пари. Визначай тільки intent.',
        ]);

        $response = $this->openAi->createStructuredResponse(
            $instructions,
            $input,
            $schema,
            'chat_order_intent_router',
            (string) ($settings['model'] ?? '')
        );

        $intent = trim((string) ($response['intent'] ?? ''));
        $confidence = isset($response['confidence']) ? (float) $response['confidence'] : 0.0;
        $reason = trim((string) ($response['reason'] ?? ''));

        if (!in_array($intent, ['none', 'single_item_quantity', 'multi_item_add', 'multi_item_edit', 'multi_item_confirm'], true)) {
            return null;
        }

        if ($confidence < 0.58) {
            return null;
        }

        return [
            'intent' => $intent,
            'source' => 'ai_classifier',
            'reason' => $reason !== '' ? $reason : 'Намір замовлення визначено AI-класифікатором.',
            'confidence' => round($confidence, 3),
        ];
    }

    /**
     * @return array{
     *     intent: string,
     *     source: string,
     *     reason: ?string,
     *     confidence: ?float
     * }
     */
    private function resolveOrderIntentFallback(
        string $text,
        ?string $previousNextSlot,
        bool $previousMultiItemPending
    ): array {
        if ($previousMultiItemPending && $this->isAffirmativeReply($text)) {
            return [
                'intent' => 'multi_item_confirm',
                'source' => 'fallback',
                'reason' => 'Клієнт підтвердив раніше зібраний список пар.',
                'confidence' => null,
            ];
        }

        if ($this->hasAdditionalPairIntentCue($text) || $this->isComplexMultiItemOrderText($text)) {
            return [
                'intent' => 'multi_item_add',
                'source' => 'fallback',
                'reason' => 'Текст схожий на багатопозиційне замовлення.',
                'confidence' => null,
            ];
        }

        if ($previousNextSlot === 'quantity' && $this->extractQuantityValue($text, $previousNextSlot) !== null) {
            return [
                'intent' => 'single_item_quantity',
                'source' => 'fallback',
                'reason' => 'Клієнт назвав кількість для однієї позиції.',
                'confidence' => null,
            ];
        }

        return [
            'intent' => 'none',
            'source' => 'fallback',
            'reason' => 'Окремий order-intent не виявлено.',
            'confidence' => null,
        ];
    }

    private function extractPhoneValue(string $text): ?string
    {
        if (!preg_match_all('/(?:\+?\d[\d\-\(\)\s]{8,}\d)/u', $text, $matches)) {
            return null;
        }

        foreach ($matches[0] as $candidate) {
            $phone = $this->normalizeSlotValue('phone', $candidate);
            if ($phone !== null) {
                return $phone;
            }
        }

        return null;
    }

    private function extractCustomerNameValue(string $text, ?string $previousNextSlot, ChatConversation $conversation): ?string
    {
        if (preg_match('/(?:мене звати|звати мене|моє ім[\'’`ʼ]я|мое имя|имя)\s+([[:alpha:]\-\'’`ʼ ]{2,60})/iu', $text, $match)) {
            return $this->normalizeSlotValue('customer_name', $match[1]);
        }

        if ($previousNextSlot !== 'customer_name') {
            return null;
        }

        if ($conversation->customer && trim((string) $conversation->customer->first_name) !== '') {
            return null;
        }

        $candidate = trim((string) preg_replace('/[0-9,.;:\/]+/u', ' ', $text));
        $candidate = trim((string) preg_replace('/\s+/u', ' ', $candidate));
        $normalizedCandidate = $this->normalizeText($candidate);
        $wordCount = count(array_filter(preg_split('/\s+/u', $candidate) ?: []));

        if (preg_match('/^я\s+([[:alpha:]\-\'’`ʼ ]{2,60})$/iu', $candidate, $match)) {
            $candidate = trim((string) $match[1]);
            $normalizedCandidate = $this->normalizeText($candidate);
            $wordCount = count(array_filter(preg_split('/\s+/u', $candidate) ?: []));
        }

        if (
            $candidate === ''
            || $wordCount < 1
            || $wordCount > 3
            || (bool) preg_match('/^(я|хочу|мені|потріб|добре|так|ні|ок|гаразд)\b/u', $normalizedCandidate)
            || (bool) preg_match('/(відділен|нова пошта|поштомат|розм|біл|чорн|сір|рожев|київ|львів|дніпро|одеса)/u', $normalizedCandidate)
            || !preg_match('/^[[:alpha:]\-\'’`ʼ ]+$/u', $candidate)
        ) {
            return null;
        }

        return $this->normalizeSlotValue('customer_name', $candidate);
    }

    private function extractPaymentValue(string $text): ?string
    {
        $normalized = $this->normalizeText($text);

        return match (true) {
            (bool) preg_match('/(передоплат|повна оплат|100%|предоплат)/u', $normalized) => 'Передоплата',
            (bool) preg_match('/(післяплат|накладен|налож|при отриман)/u', $normalized) => 'Післяплата',
            (bool) preg_match('/(карт|онлайн|mono|monobank|на карту|по реквізит)/u', $normalized) => 'Оплата карткою',
            (bool) preg_match('/(готівк|налич)/u', $normalized) => 'Готівка',
            default => null,
        };
    }

    private function extractQuantityValue(string $text, ?string $previousNextSlot): ?int
    {
        $normalized = $this->normalizeText($text);

        if (preg_match('/(?:^|[^\d])([1-9]\d?)\s*(?:шт|штук|штуки|одиниц|пари|пара|пар)\b/u', $normalized, $match)) {
            return (int) $match[1];
        }

        if (preg_match('/(?:x|х)\s*([1-9]\d?)/u', $normalized, $match)) {
            return (int) $match[1];
        }

        $wordMap = [
            'один' => 1,
            'одна' => 1,
            'одну' => 1,
            'дві' => 2,
            'два' => 2,
            'три' => 3,
            'чотири' => 4,
            'пʼять' => 5,
            "п'ять" => 5,
            'пять' => 5,
        ];

        foreach ($wordMap as $word => $value) {
            if (
                preg_match('/\b' . preg_quote($word, '/') . '\b/u', $normalized)
                && ($previousNextSlot === 'quantity' || str_contains($normalized, 'пар') || str_contains($normalized, 'штук'))
            ) {
                return $value;
            }
        }

        if (
            $previousNextSlot === 'quantity'
            && preg_match('/^\s*([1-9]\d?)\s*$/u', $normalized, $match)
            && (int) $match[1] <= 9
        ) {
            return (int) $match[1];
        }

        return null;
    }

    private function extractCityValue(string $text, ?string $previousNextSlot): ?string
    {
        $trimmed = trim((string) preg_replace('/\s+/u', ' ', $text));
        $normalizedTrimmed = $this->normalizeText($trimmed);

        if ((bool) preg_match('/^(при отриман|післяплат|накладен|на карт|оплат)/u', $normalizedTrimmed)) {
            return null;
        }

        if (preg_match('/^([^,]{2,50}),\s*(?:нова пошта|відділен|поштомат)/iu', $trimmed, $match)) {
            return $this->normalizeSlotValue('city', $match[1]);
        }

        if (preg_match('/^\s*([[:alpha:]\-\'’`ʼ ]{2,40})\s+(?:нова пошта|укрпошта|поштомат|кур[\'’`ʼ]?єр|кур[еє]р|відділен|відд\.?)/iu', $trimmed, $match)) {
            return $this->normalizeSlotValue('city', $match[1]);
        }

        if (preg_match('/(?:\bмісто\b|\bм\.)\s*([[:alpha:]\-\'’`ʼ ]{2,40})/iu', $trimmed, $match)) {
            return $this->normalizeSlotValue('city', $match[1]);
        }

        if (preg_match('/(?:доставка|відправка|нова пошта).{0,40}?\b(?:в|у)\s+([[:alpha:]\-\'’`ʼ ]{2,40})/iu', $trimmed, $match)) {
            return $this->normalizeSlotValue('city', $match[1]);
        }

        if ($previousNextSlot !== 'city') {
            return null;
        }

        $candidate = trim((string) preg_replace('/[0-9,.;:\/]+/u', ' ', $trimmed));
        $candidate = trim((string) preg_replace('/\s+/u', ' ', $candidate));
        $normalizedCandidate = $this->normalizeText($candidate);
        $wordCount = count(array_filter(preg_split('/\s+/u', $candidate) ?: []));

        if (
            $candidate === ''
            || $wordCount < 1
            || $wordCount > 3
            || (bool) preg_match('/^(я|хочу|мені|потріб|добре|так|ні|ок|гаразд)\b/u', $normalizedCandidate)
            || (bool) preg_match('/^(при отриман|післяплат|накладен|на карт|оплат)/u', $normalizedCandidate)
            || $this->containsLocationNoise($normalizedTrimmed)
            || (bool) preg_match('/(відділен|нова пошта|поштомат|розм|біл|чорн|сір|рожев)/u', $normalizedCandidate)
            || !preg_match('/^[[:alpha:]\-\'’`ʼ ]+$/u', $candidate)
        ) {
            return null;
        }

        return $this->normalizeSlotValue('city', $candidate);
    }

    private function extractDeliveryValue(string $text, ?string $previousNextSlot): ?string
    {
        $trimmed = trim((string) preg_replace('/\s+/u', ' ', $text));
        $normalizedTrimmed = $this->normalizeText($trimmed);

        if ($this->containsLocationNoise($normalizedTrimmed)) {
            return null;
        }

        if (preg_match('/((?:нова пошта|укрпошта)?\s*(?:відділення|відд\.?|поштомат)\s*№?\s*\d{1,4})/iu', $trimmed, $match)) {
            return $this->normalizeSlotValue('delivery', $match[1]);
        }

        if (preg_match('/((?:нова пошта|укрпошта)\s*(?:відділення|відд\.?|від)\s*№?\s*\d{1,4})/iu', $trimmed, $match)) {
            return $this->normalizeSlotValue('delivery', preg_replace('/\bвід\b/iu', 'відділення', $match[1]));
        }

        if (preg_match('/((?:\bвул\.?\b|\bвулиця\b|\bпроспект\b|\bпросп\.?\b)\s*[^,\n]{0,120}\d[\w\/-]*)/iu', $trimmed, $match)) {
            return $this->normalizeSlotValue('delivery', $match[1]);
        }

        if ($previousNextSlot === 'delivery' && preg_match('/^\s*\d{1,4}\s*$/u', $trimmed, $match)) {
            return $this->normalizeSlotValue('delivery', "Відділення {$match[0]}");
        }

        $hasDeliveryCue = (bool) preg_match(
            '/(відділен|поштомат|нова пошта|укрпошта|адрес|\bвул\.?\b|\bвулиця\b|\bбуд\.?\b|\bбудинок\b|\bпроспект\b|\bпросп\.?\b)/u',
            $normalizedTrimmed
        );

        if ($previousNextSlot === 'delivery' && $hasDeliveryCue && !str_contains($trimmed, '?')) {
            return $this->normalizeSlotValue('delivery', $trimmed);
        }

        return null;
    }

    /**
     * @return array{city: ?string, delivery: ?string}
     */
    private function extractBundledDeliveryDetails(string $text, ?string $previousNextSlot): array
    {
        $trimmed = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($trimmed === '' || !in_array($previousNextSlot, ['city', 'delivery'], true)) {
            return ['city' => null, 'delivery' => null];
        }

        if (preg_match('/^\s*([[:alpha:]\-\'’`ʼ ]{2,40})\s+(нова пошта|укрпошта)\s*(?:відділення|відд\.?|від)\s*№?\s*(\d{1,4})\s*$/iu', $trimmed, $match)) {
            return [
                'city' => $this->normalizeSlotValue('city', $match[1]),
                'delivery' => $this->normalizeSlotValue('delivery', "{$match[2]} відділення {$match[3]}"),
            ];
        }

        if (preg_match('/^\s*([[:alpha:]\-\'’`ʼ ]{2,40})\s+(поштомат)\s*№?\s*(\d{1,4})\s*$/iu', $trimmed, $match)) {
            return [
                'city' => $this->normalizeSlotValue('city', $match[1]),
                'delivery' => $this->normalizeSlotValue('delivery', "{$match[2]} {$match[3]}"),
            ];
        }

        if (preg_match('/^\s*([[:alpha:]\-\'’`ʼ ]{2,40})\s+(кур[\'’`ʼ]?єр|кур[еє]р)(?:ом)?\s*$/iu', $trimmed, $match)) {
            return [
                'city' => $this->normalizeSlotValue('city', $match[1]),
                'delivery' => $this->normalizeSlotValue('delivery', $match[2]),
            ];
        }

        return ['city' => null, 'delivery' => null];
    }

    private function extractColorValue(string $text, ?string $previousNextSlot): ?string
    {
        $matchedColor = $this->extractKnownColorFromText($text);
        if ($matchedColor !== null) {
            return $matchedColor;
        }

        if ($previousNextSlot !== 'color') {
            return null;
        }

        $candidate = trim((string) preg_replace('/[0-9,.;:\/]+/u', ' ', $text));
        $candidate = trim((string) preg_replace('/\s+/u', ' ', $candidate));
        $wordCount = count(array_filter(preg_split('/\s+/u', $candidate) ?: []));

        if (
            $candidate === ''
            || $wordCount < 1
            || $wordCount > 2
            || !preg_match('/^[[:alpha:]\-\'’`ʼ ]+$/u', $candidate)
        ) {
            return null;
        }

        return $this->normalizeSlotValue('color', $candidate);
    }

    private function extractKnownColorFromText(string $text): ?string
    {
        $normalized = $this->normalizeText($text);
        if ($normalized === '') {
            return null;
        }

        foreach ($this->colorStemMap() as $needle => $label) {
            if (str_contains($normalized, $needle)) {
                return $label;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function colorStemMap(): array
    {
        if ($this->cachedColorStemMap !== null) {
            return $this->cachedColorStemMap;
        }

        $map = [];

        Color::query()
            ->orderBy('name')
            ->get(['name'])
            ->each(function (Color $color) use (&$map) {
                $label = $this->normalizeHumanLabel((string) $color->name, 40);
                $normalized = $this->normalizeText($label);
                if ($normalized === '') {
                    return;
                }

                $needles = array_filter([
                    $normalized,
                    $this->buildColorStem($normalized),
                ]);

                foreach (array_unique($needles) as $needle) {
                    if (!array_key_exists($needle, $map)) {
                        $map[$needle] = $label;
                    }
                }
            });

        uksort($map, fn (string $left, string $right) => mb_strlen($right) <=> mb_strlen($left));

        return $this->cachedColorStemMap = $map;
    }

    private function buildColorStem(string $normalizedColor): string
    {
        $firstWord = trim((string) (preg_split('/\s+/u', $normalizedColor) ?: [''])[0]);
        if ($firstWord === '') {
            return '';
        }

        if (mb_strlen($firstWord) <= 4) {
            return $firstWord;
        }

        if (preg_match('/[иі]й$/u', $firstWord)) {
            return mb_substr($firstWord, 0, -2);
        }

        if (preg_match('/[аеиіоуюя]$/u', $firstWord)) {
            return mb_substr($firstWord, 0, -1);
        }

        return $firstWord;
    }

    /**
     * @return array<int, string>
     */
    private function extractVisualPreferenceStems(string $text): array
    {
        $normalized = $this->normalizeText($text);
        if ($normalized === '') {
            return [];
        }

        return collect(array_keys($this->colorStemMap()))
            ->filter(fn (string $stem) => str_contains($normalized, $stem))
            ->values()
            ->all();
    }

    private function containsLocationNoise(string $text): bool
    {
        return (bool) preg_match(
            '/(фото|фотк|покажи|показ|побачити|колаж|кольор|варіант|модель|сір|коричн|блакит|рожев|червон|малинов|електрик|капучин|чорн|біл|можна|ходити|на вулиц|ціна)/u',
            $text
        );
    }

    private function isComplexMultiItemOrderText(string $text): bool
    {
        $normalized = $this->normalizeText($text);
        if ($normalized === '') {
            return false;
        }

        $hasPairCount = (bool) preg_match('/\b(2|3|4|дві|два|три|чотири)\b/u', $normalized)
            && (bool) preg_match('/\b(пари|пара|пар)\b/u', $normalized);
        $hasSplitCue = (bool) preg_match('/(одн[аиі].{0,40}друг|перш.{0,40}друг|а другу|а другі|і ще|ще одну|ще одні|\bі\b|\bта\b)/u', $normalized);
        $colorCount = count($this->extractVisualPreferenceStems($normalized));
        $modelFamilyCount = 0;

        if ((bool) preg_match('/домашн/u', $normalized)) {
            $modelFamilyCount++;
        }

        if ((bool) preg_match('/(для вулиці|на вулицю|вуличн|резинов|гумов|суцільн)/u', $normalized)) {
            $modelFamilyCount++;
        }

        $hasMultipleVariants = $colorCount >= 2 || $modelFamilyCount >= 2;

        if ($hasPairCount && ($hasSplitCue || $hasMultipleVariants)) {
            return true;
        }

        return $hasMultipleVariants && $hasSplitCue;
    }

    private function hasAdditionalPairIntentCue(string $text): bool
    {
        $normalized = $this->normalizeText($text);
        if ($normalized === '') {
            return false;
        }

        if (
            (bool) preg_match('/\b(2|3|4|дві|два|три|чотири)\b/u', $normalized)
            && (bool) preg_match('/\b(пари|пара|пар)\b/u', $normalized)
        ) {
            return true;
        }

        return (bool) preg_match(
            '/(собі|себе|мамі|маме|мама|дружин|чоловік|чоловіку|дитин|доньк|сину|сестрі|брату|нам|ще одну|ще одні|ще пару|додай|добав|ще такі|ще таку|одні .* друг|другу пару|для двох)/u',
            $normalized
        );
    }

    private function isSingleItemResetText(string $text): bool
    {
        $normalized = $this->normalizeText($text);
        if ($normalized === '' || $this->isComplexMultiItemOrderText($text)) {
            return false;
        }

        return (bool) preg_match('/(\b1\b|\bодна\b|\bодну\b|\bодні\b|\bодин\b|лише|тільки)/u', $normalized);
    }

    private function shouldPersistRequestedSizeAsSlot(string $text, ?string $previousNextSlot): bool
    {
        $normalized = $this->normalizeText($text);
        if ($normalized === '') {
            return false;
        }

        if ($this->containsShippingNumberContext($normalized)) {
            return false;
        }

        if ($this->isFootLengthConsultationText($normalized)) {
            return false;
        }

        if ($this->isDecimalFootLengthValue($normalized)) {
            return false;
        }

        if (
            $previousNextSlot !== null
            && in_array($previousNextSlot, ['city', 'delivery', 'customer_name', 'phone', 'payment'], true)
        ) {
            return false;
        }

        return true;
    }

    private function isFootLengthConsultationText(string $text): bool
    {
        $normalized = $this->normalizeText($text);
        if ($normalized === '') {
            return false;
        }

        return (bool) preg_match('/(\bсм\b|сантим|стоп|устілк|довжин|на ногу|по нозі|по стопі|маломір)/u', $normalized)
            || $this->isDecimalFootLengthValue($normalized);
    }

    private function isDecimalFootLengthValue(string $text): bool
    {
        $normalized = $this->normalizeText($text);
        if ($normalized === '') {
            return false;
        }

        return (bool) preg_match('/^\s*(2[0-9]|3[0-9]|4[0-9]|5[0-5])[\.,]\d+\s*(см)?\s*$/u', $normalized);
    }

    /**
     * @param  array<string, array<string, mixed>>  $definitions
     * @param  array<string, mixed>  $slots
     * @return array<int, string>
     */
    private function resolveMissingSlotKeys(array $definitions, array $slots): array
    {
        $missing = [];

        foreach ($definitions as $key => $definition) {
            if (!(bool) ($definition['required'] ?? false)) {
                continue;
            }

            if ($this->hasSlotValue($slots[$key] ?? null, $key)) {
                continue;
            }

            $missing[] = $key;
        }

        return $missing;
    }

    /**
     * @param  array<string, array<string, mixed>>  $definitions
     * @param  array<string, mixed>  $slots
     */
    private function buildSlotSummary(
        array $definitions,
        array $slots,
        array $missing,
        ?string $nextSlot,
        bool $orderReady,
        bool $singleItemReviewPending = false,
        bool $multiItemPending = false
    ): string {
        $filled = collect($definitions)
            ->map(function (array $definition, string $key) use ($slots) {
                if (!$this->hasSlotValue($slots[$key] ?? null, $key)) {
                    return null;
                }

                $label = (string) ($definition['label'] ?? $key);
                $value = $this->formatSlotValue($key, $slots[$key] ?? null);

                return $value !== '' ? "{$label}: {$value}" : null;
            })
            ->filter()
            ->values()
            ->implode('; ');

        $missingLabels = collect($missing)
            ->map(fn (string $key) => (string) ($definitions[$key]['label'] ?? $key))
            ->implode(', ');

        $parts = [];

        if ($filled !== '') {
            $parts[] = "Зібрано: {$filled}";
        }

        if ($missingLabels !== '') {
            $parts[] = "Не вистачає: {$missingLabels}";
        }

        if ($nextSlot !== null && isset($definitions[$nextSlot])) {
            $parts[] = 'Наступний слот: ' . $definitions[$nextSlot]['label'];
        }

        if ($singleItemReviewPending) {
            $parts[] = 'Потрібно підтвердити сформовану позицію перед оформленням';
        }

        if ($multiItemPending) {
            $parts[] = 'Потрібно підтвердити список кількох пар перед оформленням';
        }

        if ($orderReady) {
            $parts[] = 'Усі обов’язкові дані зібрано';
        }

        return implode('. ', array_filter($parts));
    }

    /**
     * @param  array<string, array<string, mixed>>  $definitions
     * @param  array<string, mixed>  $slots
     * @param  array<int, string>  $missing
     */
    private function buildSlotStateBlock(
        array $definitions,
        array $slots,
        array $missing,
        ?string $nextSlot,
        bool $orderReady,
        bool $singleItemReviewPending = false,
        bool $multiItemPending = false
    ): string {
        $lines = ['Стан слотів замовлення:'];

        foreach ($definitions as $key => $definition) {
            $label = (string) ($definition['label'] ?? $key);
            $requiredLabel = (bool) ($definition['required'] ?? false) ? 'обов’язково' : 'необов’язково';
            $value = $this->formatSlotValue($key, $slots[$key] ?? null);

            $lines[] = "- {$label} ({$requiredLabel}): " . ($value !== '' ? $value : 'не зібрано');
        }

        if ($missing !== []) {
            $missingLabels = collect($missing)
                ->map(fn (string $key) => (string) ($definitions[$key]['label'] ?? $key))
                ->implode(', ');

            $lines[] = "Не вистачає: {$missingLabels}";
        }

        if ($nextSlot !== null && isset($definitions[$nextSlot])) {
            $lines[] = 'Наступний слот для уточнення: ' . $definitions[$nextSlot]['label'];
        }

        $lines[] = $orderReady
            ? 'Усі обов’язкові слоти зібрані: так'
            : 'Усі обов’язкові слоти зібрані: ні';

        if ($singleItemReviewPending) {
            $lines[] = 'Підтвердження однієї позиції: так. Не переходь до доставки, поки клієнт не підтвердив сформовану позицію.';
        }

        if ($multiItemPending) {
            $lines[] = 'Багатопозиційне замовлення: так. Не переходь до доставки, поки не підтверджені всі пари.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $slotState
     */
    private function buildOrderReadyReply(array $slotState): string
    {
        $slots = is_array($slotState['slots'] ?? null)
            ? $slotState['slots']
            : [];

        $parts = [];

        foreach (['model', 'color', 'size', 'quantity', 'city', 'delivery', 'customer_name', 'phone', 'payment'] as $key) {
            $value = $this->formatSlotValue($key, $slots[$key] ?? null);
            if ($value === '') {
                continue;
            }

            $label = match ($key) {
                'model' => 'модель',
                'color' => 'колір',
                'size' => 'розмір',
                'quantity' => 'кількість',
                'city' => 'місто',
                'delivery' => 'доставка',
                'customer_name' => "ім'я",
                'phone' => 'телефон',
                'payment' => 'оплата',
                default => $key,
            };

            $parts[] = "{$label}: {$value}";
        }

        $details = implode(', ', $parts);

        return $details !== ''
            ? "Дякую, зафіксував замовлення: {$details}. Передаю менеджеру для підтвердження."
            : 'Дякую, дані для замовлення отримано. Передаю менеджеру для підтвердження.';
    }

    private function hasSlotValue(mixed $value, string $key): bool
    {
        return $this->normalizeSlotValue($key, $value) !== null;
    }

    private function slotValuesEqual(string $key, mixed $first, mixed $second): bool
    {
        return $this->normalizeSlotValue($key, $first) === $this->normalizeSlotValue($key, $second);
    }

    private function normalizeSlotValue(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($key) {
            'size' => $this->normalizeSizeSlotValue($value),
            'quantity' => $this->normalizeQuantitySlotValue($value),
            'phone' => $this->normalizePhoneSlotValue($value),
            'customer_name' => $this->normalizeHumanLabel($value, 80),
            'city' => $this->normalizeHumanLabel($value, 80),
            'color' => $this->normalizeHumanLabel($value, 40),
            'payment' => $this->trimSlotString($value, 60),
            'delivery' => $this->trimSlotString($value, 120),
            'model' => $this->trimSlotString($value, 120),
            default => $this->trimSlotString($value, 120),
        };
    }

    private function normalizeSizeSlotValue(mixed $value): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        $text = str_replace(['–', '—'], '-', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        // Для рядків варіантів на кшталт "36/37р - 24-24,5см" спочатку беремо саме розмір,
        // а не діапазон сантиметрів, який іде далі в описі.
        if (preg_match('/^\s*([2-5]\d)\s*\/\s*([2-5]\d)\s*(?:р|рр|р\.)?(?=$|[^\d])/u', $text, $match)) {
            return "{$match[1]}/{$match[2]}";
        }

        if (preg_match('/^\s*([2-5]\d)\s*-\s*([2-5]\d)\s*(?:р|рр|р\.)?(?=$|[^\d])/u', $text, $match)) {
            return "{$match[1]}/{$match[2]}";
        }

        if (preg_match('/^\s*([2-5]\d)\s*(?:р|рр|р\.)?(?=$|[^\d])/u', $text, $match)) {
            return $match[1];
        }

        $compactText = preg_replace('/\s+/u', '', $text);
        $compactText = trim((string) $compactText);

        if ($compactText === '') {
            return null;
        }

        if (preg_match('/(?<!\d)([2-5]\d)\s*\/\s*([2-5]\d)(?!\d)/u', $compactText, $match)) {
            return "{$match[1]}/{$match[2]}";
        }

        if (preg_match('/(?<!\d)([2-5]\d)\s*-\s*([2-5]\d)(?!\d)/u', $compactText, $match)) {
            return "{$match[1]}/{$match[2]}";
        }

        if (preg_match('/(?<!\d)([2-5]\d)(?!\d)/u', $compactText, $match)) {
            return $match[1];
        }

        return null;
    }

    private function normalizeQuantitySlotValue(mixed $value): ?int
    {
        $number = (int) $value;

        return $number >= 1 && $number <= 99 ? $number : null;
    }

    private function normalizePhoneSlotValue(mixed $value): ?string
    {
        $digits = preg_replace('/\D/u', '', (string) $value);
        $digits = trim((string) $digits);

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = '38' . $digits;
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '380')) {
            return $digits;
        }

        return null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $products
     */
    private function resolveRequestedSizeSlotValue(string $text, Collection $products, int $requestedSize): ?string
    {
        $explicitSize = $this->extractExplicitSizeSlotValue($text);
        $sizeLabels = $products
            ->flatMap(function (array $product) {
                $availableSizes = collect((array) ($product['available_sizes'] ?? []))
                    ->filter(fn ($size) => trim((string) $size) !== '');

                if ($availableSizes->isNotEmpty()) {
                    return $availableSizes->values();
                }

                return collect((array) ($product['all_sizes'] ?? $product['sizes'] ?? []))
                    ->filter(fn ($size) => trim((string) $size) !== '')
                    ->values();
            })
            ->map(fn ($size) => $this->normalizeSizeSlotValue($size))
            ->filter()
            ->unique()
            ->values();

        if ($explicitSize !== null) {
            $matchedExplicit = $sizeLabels
                ->first(fn (string $label) => $label === $explicitSize);

            if (is_string($matchedExplicit) && $matchedExplicit !== '') {
                return $matchedExplicit;
            }

            if (str_contains($explicitSize, '/')) {
                return $explicitSize;
            }
        }

        $singleMatch = $sizeLabels
            ->first(fn (string $label) => $label === (string) $requestedSize);

        if (is_string($singleMatch) && $singleMatch !== '') {
            return $singleMatch;
        }

        $rangeMatch = $sizeLabels
            ->first(function (string $label) use ($requestedSize) {
                $numbers = $this->extractAllNumbers($label);
                if ($numbers === []) {
                    return false;
                }

                if (count($numbers) >= 2) {
                    $min = min($numbers[0], $numbers[1]);
                    $max = max($numbers[0], $numbers[1]);

                    return $requestedSize >= $min && $requestedSize <= $max;
                }

                return in_array($requestedSize, $numbers, true);
            });

        if (is_string($rangeMatch) && $rangeMatch !== '') {
            return $rangeMatch;
        }

        if ($explicitSize !== null) {
            return $explicitSize;
        }

        return $this->normalizeSizeSlotValue((string) $requestedSize);
    }

    private function extractExplicitSizeSlotValue(string $text): ?string
    {
        $normalized = $this->normalizeText($text);
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/(?<!\d)([2-5]\d)\s*[\/\-–]\s*([2-5]\d)(?!\d)/u', $normalized, $match)) {
            return $this->normalizeSizeSlotValue("{$match[1]}/{$match[2]}");
        }

        return null;
    }

    private function normalizeHumanLabel(mixed $value, int $limit): ?string
    {
        $trimmed = $this->trimSlotString($value, $limit);
        if ($trimmed === null) {
            return null;
        }

        return mb_convert_case($trimmed, MB_CASE_TITLE, 'UTF-8');
    }

    private function trimSlotString(mixed $value, int $limit): ?string
    {
        $trimmed = trim((string) preg_replace('/\s+/u', ' ', (string) $value));
        if ($trimmed === '') {
            return null;
        }

        return Str::limit($trimmed, $limit, '');
    }

    private function formatSlotValue(string $key, mixed $value): string
    {
        $normalized = $this->normalizeSlotValue($key, $value);
        if ($normalized === null) {
            return '';
        }

        return match ($key) {
            'quantity' => "{$normalized} шт.",
            default => (string) $normalized,
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $products
     * @param  Collection<int, array<string, mixed>>  $selectedMedia
     */
    private function buildTopicBlock(
        Collection $topics,
        ?ChatAiTopic $topic,
        Collection $products,
        Collection $selectedMedia,
        ?int $requestedSize,
        string $messageText,
        bool $isPhotoRequest,
        bool $isAllPhotosRequest,
        bool $shouldSendOverviewMedia
    ): string {
        $productsText = $products
            ->take(20)
            ->map(function (array $product, int $index) {
                $variantLines = collect((array) ($product['variant_inventory'] ?? []))
                    ->map(function (array $variant) {
                        $size = trim((string) ($variant['size'] ?? ''));
                        if ($size === '') {
                            return null;
                        }

                        $stockQty = max(0, (int) ($variant['stock_qty'] ?? 0));

                        return "   - {$size} — {$stockQty} шт.";
                    })
                    ->filter()
                    ->implode("\n");
                $price = $product['price'] !== null
                    ? number_format((float) $product['price'], 0, '.', '') . ' грн'
                    : 'ціна не вказана';
                $availability = (bool) ($product['is_available'] ?? false)
                    ? 'в наявності'
                    : 'немає в наявності';
                $photoState = (bool) ($product['has_photo'] ?? false)
                    ? 'фото є'
                    : 'фото немає';
                $color = trim((string) ($product['color_name'] ?? ''));
                $requestedSizeStock = isset($product['requested_size_stock_qty'])
                    ? (int) ($product['requested_size_stock_qty'] ?? 0)
                    : null;

                return ($index + 1) . '. '
                    . ($product['title'] ?? 'Товар')
                    . ($product['sku'] ? " (SKU: {$product['sku']})" : '')
                    . " — {$price}"
                    . ($color !== '' ? "\n   колір: {$color}" : '')
                    . "\n   статус: {$availability}, {$photoState}"
                    . ($requestedSizeStock !== null ? "\n   залишок по запитаному розміру: {$requestedSizeStock} шт." : '')
                    . "\n   варіанти і залишки:\n"
                    . ($variantLines !== '' ? $variantLines : '   - не вказано');
            })
            ->implode("\n");

        $mediaText = $selectedMedia
            ->take(30)
            ->map(function (array $media, int $index) {
                $label = trim((string) ($media['label'] ?? ''));
                $type = (string) ($media['media_type'] ?? 'image');

                return ($index + 1) . '. '
                    . ($label !== '' ? $label : 'Медіа')
                    . " [{$type}]";
            })
            ->implode("\n");

        $availableModels = $topic === null
            ? $this->buildAvailableTopicList($topics)
            : '';

        return trim(implode("\n", array_filter([
            $topic ? "Поточна тема: {$topic->name}" : 'Поточна тема: не визначена',
            $availableModels !== '' ? "Доступні моделі для вибору:\n{$availableModels}" : null,
            $topic && trim((string) $topic->instruction) !== '' ? "Інструкція теми: {$topic->instruction}" : null,
            $this->buildRequestedSizeContextLine($requestedSize, $messageText),
            $isPhotoRequest ? 'Клієнт просить фото: так' : 'Клієнт просить фото: ні',
            $isAllPhotosRequest ? 'Клієнт просить показати всі фото: так' : null,
            $shouldSendOverviewMedia ? 'Система надсилає оглядові колажі доступних моделей: так' : null,
            $productsText !== '' ? "Релевантні товари:\n{$productsText}" : 'Релевантні товари: немає',
            $mediaText !== '' ? "Медіа, які система може надіслати зараз:\n{$mediaText}" : 'Медіа для поточної теми: немає',
        ])));
    }

    private function buildRequestedSizeContextLine(?int $requestedSize, string $messageText): string
    {
        if ($requestedSize === null) {
            return 'Запитаний розмір: не вказаний';
        }

        if ($this->isFootLengthConsultationText($messageText)) {
            return "Клієнт назвав довжину стопи або промір у сантиметрах: {$requestedSize}";
        }

        return "Запитаний розмір: {$requestedSize}";
    }

    /**
     * @param  Collection<int, ChatAiTopic>  $topics
     */
    private function buildAvailableTopicList(Collection $topics): string
    {
        return $topics
            ->sortBy('priority')
            ->map(fn (ChatAiTopic $topic) => '- ' . trim((string) $topic->name))
            ->filter(fn (string $line) => trim($line) !== '-')
            ->values()
            ->implode("\n");
    }

    private function buildUnknownTopicStatusNote(int $sentMediaCount): string
    {
        if ($sentMediaCount > 0) {
            return "Тема не визначена, AI надіслав {$sentMediaCount} медіа для вибору моделі.";
        }

        return 'Тема не визначена, AI попросив клієнта уточнити модель або категорію.';
    }

    /**
     * @param  array<string, mixed>  $slotState
     * @param  Collection<int, array<string, mixed>>  $overviewMedia
     */
    private function shouldSendTopicOverviewForModelSelection(
        string $normalizedMessageText,
        ?int $requestedSize,
        array $slotState,
        Collection $overviewMedia
    ): bool {
        if ($overviewMedia->isEmpty()) {
            return false;
        }

        $nextSlot = is_string($slotState['next'] ?? null) ? $slotState['next'] : null;
        if ($nextSlot !== 'model') {
            return false;
        }

        if ($requestedSize !== null) {
            return true;
        }

        if ($normalizedMessageText === '') {
            return false;
        }

        // Поки модель не визначена, для будь-якого осмисленого повідомлення
        // показуємо всі колажі доступних моделей, щоб клієнт обрав варіант одразу.
        return mb_strlen($normalizedMessageText) >= 1;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $selectedMedia
     */
    private function sendMediaMessages(ChatConversation $conversation, Collection $selectedMedia): int
    {
        $contact = $conversation->contact;
        $customer = $conversation->customer;

        if (!$contact || !$customer) {
            return 0;
        }

        $sentCount = 0;
        foreach ($selectedMedia as $media) {
            $url = trim((string) ($media['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $metaResult = $this->metaService->sendMessage(
                $customer,
                '',
                [['type' => 'image', 'url' => $url]],
                (string) $contact->platform,
                (string) $contact->external_user_id
            );

            if (!$metaResult) {
                Log::warning('AI: не вдалося надіслати медіа у Meta', [
                    'conversation_id' => $conversation->id,
                    'url' => $url,
                ]);
                continue;
            }

            $message = $this->chatService->storeMessage($conversation, [
                'direction' => 'outbound',
                'source' => 'system',
                'external_message_id' => $metaResult['message_id'] ?? null,
                'delivery_status' => 'sent',
                'sent_at' => now(),
                'meta' => [
                    'ai' => [
                        'kind' => 'media',
                        'topic_media_id' => $media['topic_media_id'] ?? null,
                        'topic_product_id' => $media['topic_product_id'] ?? null,
                        'product_id' => $media['product_id'] ?? null,
                    ],
                ],
            ], [[
                'type' => 'image',
                'url' => $url,
                'meta' => [
                    'ai' => true,
                    'source' => $media['source'] ?? null,
                    'label' => $media['label'] ?? null,
                ],
            ]]);

            $this->chatService->updateConversationAfterMessage($conversation, $message, false);
            $sentCount++;
        }

        return $sentCount;
    }

    private function sendTextMessage(ChatConversation $conversation, string $text): bool
    {
        $contact = $conversation->contact;
        $customer = $conversation->customer;

        if (!$contact || !$customer) {
            return false;
        }

        $metaResult = $this->metaService->sendMessage(
            $customer,
            $text,
            [],
            (string) $contact->platform,
            (string) $contact->external_user_id
        );

        if (!$metaResult) {
            Log::warning('AI: не вдалося надіслати текст у Meta', [
                'conversation_id' => $conversation->id,
                'text' => Str::limit($text, 200),
            ]);

            return false;
        }

        $message = $this->chatService->storeMessage($conversation, [
            'direction' => 'outbound',
            'source' => 'system',
            'external_message_id' => $metaResult['message_id'] ?? null,
            'delivery_status' => 'sent',
            'text' => $text,
            'sent_at' => now(),
            'meta' => [
                'ai' => [
                    'kind' => 'text',
                ],
            ],
        ]);

        $this->chatService->updateConversationAfterMessage($conversation, $message, false);

        return true;
    }

    private function isConversationAiEnabled(ChatConversation $conversation): bool
    {
        $value = data_get($conversation->meta, 'ai.enabled');

        if ($value === null) {
            return true;
        }

        return (bool) $value;
    }

    /**
     * @param  array<string, mixed>  $extraMeta
     */
    private function setConversationAiEnabled(ChatConversation $conversation, bool $enabled, array $extraMeta = []): void
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $ai = is_array(data_get($meta, 'ai')) ? data_get($meta, 'ai') : [];

        $ai['enabled'] = $enabled;
        $ai['updated_at'] = now()->toIso8601String();

        foreach ($extraMeta as $key => $value) {
            $ai[$key] = $value;
        }

        $meta['ai'] = $ai;
        $conversation->meta = $meta;
        $conversation->save();
    }

    /**
     * @param  array<string, mixed>  $contextMeta
     */
    private function syncConversationAiContext(ChatConversation $conversation, ?ChatAiTopic $topic, array $contextMeta = []): void
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $ai = is_array(data_get($meta, 'ai')) ? data_get($meta, 'ai') : [];

        $ai['enabled'] = array_key_exists('enabled', $ai) ? (bool) $ai['enabled'] : true;
        $ai['last_reply_at'] = now()->toIso8601String();

        if ($topic !== null) {
            $ai['last_topic_id'] = $topic->id;
            $ai['last_topic_name'] = $topic->name;
        }

        foreach ($contextMeta as $key => $value) {
            if ($key === 'last_requested_size' && $value === null) {
                continue;
            }

            $ai[$key] = $value;
        }

        $meta['ai'] = $ai;
        $conversation->meta = $meta;
        $conversation->save();
    }

    /**
     * @param  array<string, mixed>  $contextMeta
     */
    private function setConversationAiStatus(ChatConversation $conversation, array $contextMeta = []): void
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $ai = is_array(data_get($meta, 'ai')) ? data_get($meta, 'ai') : [];

        foreach ($contextMeta as $key => $value) {
            $ai[$key] = $value;
        }

        $ai['updated_at'] = now()->toIso8601String();

        $meta['ai'] = $ai;
        $conversation->meta = $meta;
        $conversation->save();
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function markMessageAiState(ChatMessage $message, array $state): void
    {
        $meta = is_array($message->meta) ? $message->meta : [];
        $ai = is_array(data_get($meta, 'ai')) ? data_get($meta, 'ai') : [];

        foreach ($state as $key => $value) {
            $ai[$key] = $value;
        }
        $ai['processed_at'] = now()->toIso8601String();

        $meta['ai'] = $ai;
        $message->meta = $meta;
        $message->save();
    }

    private function buildSkipStatusNote(string $skipReason): string
    {
        return match ($skipReason) {
            'conversation_context_missing' => 'Немає повного контексту діалогу для AI.',
            'not_inbound' => 'AI пропустив повідомлення, бо воно не вхідне.',
            'conversation_not_open' => 'AI не відповідає, бо діалог закритий або в архіві.',
            'openai_key_missing' => 'Відсутній OPENAI_API_KEY, AI тимчасово не працює.',
            'ai_disabled_global' => 'AI глобально вимкнений у системі.',
            'ai_disabled_conversation' => 'AI вимкнений для цього діалогу.',
            'not_last_message' => 'Клієнт надіслав новіше повідомлення, AI пропустив старе.',
            'already_processed' => 'Це повідомлення вже оброблене AI.',
            'operator_already_replied' => 'Менеджер уже відповів у діалозі.',
            default => 'AI пропустив це повідомлення за умовами безпеки.',
        };
    }

    private function buildRuntimeStatusNote(
        ?ChatAiTopic $topic,
        ?int $requestedSize,
        bool $isPhotoRequest,
        int $sentMediaCount
    ): string {
        $topicName = trim((string) ($topic?->name ?? ''));

        if ($isPhotoRequest && $sentMediaCount > 0) {
            $topicText = $topicName !== '' ? " по темі «{$topicName}»" : '';
            return "AI надіслав {$sentMediaCount} фото{$topicText}.";
        }

        if ($isPhotoRequest && $sentMediaCount === 0) {
            return 'Клієнт просив фото, але в темі немає доступних медіа.';
        }

        if ($requestedSize !== null) {
            return "AI опрацював запит по розміру {$requestedSize}.";
        }

        if ($topicName !== '') {
            return "AI працює в темі «{$topicName}».";
        }

        return 'AI відповів клієнту у вільному режимі без визначеної теми.';
    }

    private function isPhotoRequest(string $text): bool
    {
        $normalized = $this->normalizeText($text);
        if ($normalized === '') {
            return false;
        }

        if ((bool) preg_match(
            '/(фото|фотк|картин|зображ|колаж|палiтр|палітр)/u',
            $normalized
        )) {
            return true;
        }

        $hasShowVerb = (bool) preg_match(
            '/(покажи|покажiть|покажіть|показат|побачити|подивит|глянути|скинь|скидайте|надішли|надiшли|надсилайте|можна показати|можна побачити|можна глянути|хочу глянути|хочу подивит|хочу побачити)/u',
            $normalized
        );

        return $hasShowVerb;
    }

    private function isAllPhotosRequest(string $text): bool
    {
        $normalized = $this->normalizeText($text);
        if ($normalized === '') {
            return false;
        }

        return (bool) preg_match(
            '/(всi|всі|усi|усі|все|усе|які є|якi є|яки є|що є|весь асортимент|всі варіанти|усі варіанти|всі кольори|усі кольори)/u',
            $normalized
        );
    }

    private function isBroadCatalogRequest(string $normalizedText): bool
    {
        if ($normalizedText === '') {
            return false;
        }

        return (bool) preg_match(
            '/(які маєте|якi маєте|яки маєте|що маєте|які у вас є|якi у вас є|яки у вас є|що у вас є|які є|якi є|яки є|що є|асортимент)/u',
            $normalizedText
        );
    }

    /**
     * @param  array<string, mixed>  $slotState
     */
    private function shouldSendAllCurrentModelPhotos(
        string $text,
        bool $explicitPhotoRequest,
        array $slotState,
        ?ChatAiTopic $topic,
        ChatConversation $conversation
    ): bool {
        if (!$this->hasSelectedModelContext($topic, $slotState, $conversation)) {
            return false;
        }

        $normalized = $this->normalizeText($text);
        if ($normalized === '') {
            return false;
        }

        $slots = is_array($slotState['slots'] ?? null) ? $slotState['slots'] : [];
        $hasSelectedColor = $this->hasSlotValue($slots['color'] ?? null, 'color');

        if ($explicitPhotoRequest && !$hasSelectedColor) {
            return true;
        }

        if ($this->isAllPhotosRequest($text) || $this->isBroadCatalogRequest($normalized)) {
            return true;
        }

        return (bool) preg_match(
            '/(які є ще|якi є ще|яки є ще|а які є|а якi є|а яки є|ще які|ще якi|ще яки|ще фото|покажи ще|ще покажи|які кольори|якi кольори|яки кольори|всі кольори|усі кольори|інші кольори|инші кольори|які варіанти|якi варіанти|яки варіанти|всі варіанти|усі варіанти|всі які є|усі які є)/u',
            $normalized
        );
    }

    private function isAmbiguousVisualIntent(string $text): bool
    {
        $normalized = $this->normalizeText($text);
        if ($normalized === '' || $this->isPhotoRequest($text)) {
            return false;
        }

        return (bool) preg_match(
            '/(можна показати|хочу глянути|хочу подивит|хочу побачити|можна глянути|можна подивит|можна побачити|а можна показати|глянути|подивит|побачити|показати)/u',
            $normalized
        );
    }

    private function isAffirmativeReply(string $text): bool
    {
        $normalized = $this->normalizeText($text);
        if ($normalized === '') {
            return false;
        }

        return (bool) preg_match(
            '/^(так|ага|угу|давайте|показуйте|покажіть|покажи|скидайте|надсилайте|надiшлiть|надiшли|хочу|можна)([\s!.,?].*)?$/u',
            $normalized
        );
    }

    private function isNegativeReply(string $text): bool
    {
        $normalized = $this->normalizeText($text);
        if ($normalized === '') {
            return false;
        }

        return (bool) preg_match('/^(ні|не треба|не потрібно|не хочу|неа|не треба показувати)([\s!.,?].*)?$/u', $normalized);
    }

    /**
     * @param  array<string, mixed>  $slotState
     */
    private function buildMediaSelectionQuery(
        string $messageText,
        array $slotState,
        ?ChatAiTopic $topic,
        bool $forceContext,
        ?string $forcedColorHint = null
    ): string {
        $parts = [];
        $normalized = $this->normalizeText($messageText);
        $slots = is_array($slotState['slots'] ?? null) ? $slotState['slots'] : [];

        if (trim($messageText) !== '') {
            $parts[] = trim($messageText);
        }

        $color = $this->hasSlotValue($slots['color'] ?? null, 'color')
            ? (string) $slots['color']
            : '';
        $model = $this->hasSlotValue($slots['model'] ?? null, 'model')
            ? (string) $slots['model']
            : ($topic?->name ?? '');

        if ($color !== '' && (!$this->messageContainsVisualPreference($normalized) || $forceContext)) {
            $parts[] = $color;
        }

        $forcedColor = $this->normalizeSlotValue('color', $forcedColorHint);
        if (
            is_string($forcedColor)
            && $forcedColor !== ''
            && (!$this->messageContainsVisualPreference($normalized) || $forceContext)
        ) {
            $parts[] = $forcedColor;
        }

        if ($model !== '' && (!$this->messageContainsModelReference($normalized) || $forceContext)) {
            $parts[] = $model;
        }

        if ($forceContext && !preg_match('/(фото|фотк|картин|зображ|колаж|палітр|палiтр)/u', $normalized)) {
            $parts[] = 'фото';
        }

        return collect($parts)
            ->map(fn ($part) => trim((string) $part))
            ->filter()
            ->unique()
            ->implode(' ');
    }

    private function messageContainsVisualPreference(string $normalizedText): bool
    {
        return $this->extractVisualPreferenceStems($normalizedText) !== [];
    }

    private function messageContainsModelReference(string $normalizedText): bool
    {
        if ($normalizedText === '') {
            return false;
        }

        return (bool) preg_match('/(halluci|модел|варіант|капц|тапул|тапк)/u', $normalizedText);
    }

    private function extractRequestedSize(?string $text): ?int
    {
        $normalized = $this->normalizeText((string) $text);
        if ($normalized === '') {
            return null;
        }

        if ($this->containsShippingNumberContext($normalized)) {
            return null;
        }

        $isShortReply = count(array_filter(preg_split('/\s+/u', $normalized) ?: [])) <= 3;
        $hasSizeCue = (bool) preg_match(
            '/(розмір|розм|size|см|стоп|устілк|довжин|на ногу|по нозі|по стопі)/u',
            $normalized
        );

        if (($isShortReply || $hasSizeCue) && preg_match('/(?<!\d)([2-5]\d)(?!\d)/u', $normalized, $match)) {
            $size = (int) $match[1];
            if ($size >= 20 && $size <= 55) {
                return $size;
            }
        }

        return null;
    }

    private function containsShippingNumberContext(string $text): bool
    {
        return (bool) preg_match(
            '/(відділен|нова пошта|поштомат|адрес|вулиц|\bвул\.?|\bбуд\.?|будин|квартир|\bкв\.?|під[\'’`ʼ]?їзд|телефон|номер)/u',
            $text
        );
    }

    /**
     * @param  Collection<int, ProductVariant>  $variants
     */
    private function productMatchesRequestedSize(Collection $variants, int $requestedSize): bool
    {
        foreach ($variants as $variant) {
            $sizeText = (string) ($variant->size ?? '');
            if ($sizeText === '') {
                continue;
            }

            $numbers = $this->extractAllNumbers($sizeText);
            if ($numbers === []) {
                continue;
            }

            if (in_array($requestedSize, $numbers, true)) {
                return true;
            }

            if (count($numbers) >= 2) {
                $min = min($numbers[0], $numbers[1]);
                $max = max($numbers[0], $numbers[1]);
                if ($requestedSize >= $min && $requestedSize <= $max) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $variantInventory
     */
    private function resolveRequestedSizeStock(Collection $variantInventory, int $requestedSize): int
    {
        return (int) $variantInventory
            ->filter(function (array $variant) use ($requestedSize) {
                $sizeText = trim((string) ($variant['size'] ?? ''));
                if ($sizeText === '') {
                    return false;
                }

                $numbers = $this->extractAllNumbers($sizeText);
                if ($numbers === []) {
                    return false;
                }

                if (in_array($requestedSize, $numbers, true)) {
                    return true;
                }

                if (count($numbers) >= 2) {
                    $min = min($numbers[0], $numbers[1]);
                    $max = max($numbers[0], $numbers[1]);

                    return $requestedSize >= $min && $requestedSize <= $max;
                }

                return false;
            })
            ->sum(fn (array $variant) => max(0, (int) ($variant['stock_qty'] ?? 0)));
    }

    /**
     * @return array<int, int>
     */
    private function extractAllNumbers(string $text): array
    {
        if (!preg_match_all('/\d{2}/u', $text, $matches)) {
            return [];
        }

        return collect($matches[0])
            ->map(fn ($item) => (int) $item)
            ->filter(fn ($item) => $item >= 20 && $item <= 55)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function explodeKeywordPhrase(string $phrase): array
    {
        return collect(preg_split('/[\n,;|]+/u', $phrase))
            ->map(fn ($item) => $this->normalizeText((string) $item))
            ->filter(fn ($item) => $item !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeText(string $text): string
    {
        $normalized = mb_strtolower(trim($text));
        $normalized = str_replace(['’', '`', 'ʼ'], "'", $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized);

        return trim((string) $normalized);
    }

    private function absoluteUrl(?string $url): ?string
    {
        $value = trim((string) $url);
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        return url(ltrim($value, '/'));
    }
}
