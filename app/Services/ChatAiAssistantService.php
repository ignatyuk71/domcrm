<?php

namespace App\Services;

use App\Models\ChatAiResponseRule;
use App\Models\ChatAiTopic;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChatAiAssistantService
{
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
        $topicMatch = $this->matchTopic(
            $topics,
            (string) ($message->text ?? ''),
            $conversation
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

        $awaitingPhotoConfirmation = $this->isAwaitingPhotoConfirmation($conversation);
        $explicitPhotoRequest = $this->isPhotoRequest((string) ($message->text ?? ''));
        $confirmedPhotoRequest = $awaitingPhotoConfirmation && $this->isAffirmativeReply((string) ($message->text ?? ''));
        $declinedPhotoRequest = $awaitingPhotoConfirmation && $this->isNegativeReply((string) ($message->text ?? ''));
        $ambiguousVisualIntent = !$explicitPhotoRequest
            && !$confirmedPhotoRequest
            && !$declinedPhotoRequest
            && $this->isAmbiguousVisualIntent((string) ($message->text ?? ''));
        $hasResolvedVisualContext = $this->hasResolvedVisualContext($topic, $slotState, $conversation);
        $isPhotoRequest = $explicitPhotoRequest || $confirmedPhotoRequest;
        $isAllPhotosRequest = $explicitPhotoRequest && $this->isAllPhotosRequest((string) ($message->text ?? ''));
        $isBroadCatalogRequest = $this->isBroadCatalogRequest($normalizedMessageText);
        $shouldSendOverviewMedia = $isTopicUnclear && ($isBroadCatalogRequest || $isAllPhotosRequest);
        $mediaSelectionQuery = $this->buildMediaSelectionQuery(
            (string) ($message->text ?? ''),
            $slotState,
            $topic,
            $isPhotoRequest
        );
        $previewMedia = $mediaCandidates->isNotEmpty()
            ? $this->selectMediaForReply($mediaCandidates, $mediaSelectionQuery, false)
            : collect();
        $shouldAskPhotoConfirmation = $ambiguousVisualIntent
            && $hasResolvedVisualContext
            && $previewMedia->isNotEmpty();

        $selectedMedia = $shouldSendOverviewMedia
            ? $this->resolveAllTopicsOverviewMedia($topics)
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
                    $topic,
                    $products,
                    $mediaForPrompt,
                    $slotState,
                    $requestedSize,
                    $isPhotoRequest,
                    $isAllPhotosRequest,
                    $shouldAskPhotoConfirmation
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
            'slot_definitions' => $slotState['definitions'],
            'slot_values' => $slotState['slots'],
            'missing_slots' => $slotState['missing'],
            'next_slot' => $slotState['next'],
            'order_ready' => $slotState['order_ready'],
            'slot_summary' => $slotState['summary'],
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
            'slot_updates' => $slotState['updated'],
            'slot_values' => $slotState['slots'],
            'missing_slots' => $slotState['missing'],
            'next_slot' => $slotState['next'],
            'order_ready' => $slotState['order_ready'],
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
     * @return array{topic: ?ChatAiTopic, score: int}
     */
    private function matchTopic(Collection $topics, string $text, ChatConversation $conversation): array
    {
        if ($topics->isEmpty()) {
            return ['topic' => null, 'score' => 0];
        }

        $normalizedText = $this->normalizeText($text);
        $isBroadCatalogRequest = $this->isBroadCatalogRequest($normalizedText);
        $lastTopicId = (int) data_get($conversation->meta, 'ai.last_topic_id', 0);

        $bestTopic = null;
        $bestScore = PHP_INT_MIN;

        foreach ($topics as $topic) {
            $score = 0;

            if (!$isBroadCatalogRequest && $lastTopicId > 0 && $lastTopicId === (int) $topic->id) {
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
            return ['topic' => null, 'score' => 0];
        }

        // Якщо запит загальний ("які маєте", "що є в наявності"), не тягнемо попередню тему:
        // потрібно показати оглядові варіанти.
        if ($bestScore <= 0 && $isBroadCatalogRequest) {
            return ['topic' => null, 'score' => $bestScore];
        }

        if ($bestScore <= 0 && $topics->count() === 1) {
            return ['topic' => $topics->first(), 'score' => 1];
        }

        if ($bestScore <= 0 && $lastTopicId > 0) {
            $fallback = $topics->firstWhere('id', $lastTopicId);
            if ($fallback) {
                return ['topic' => $fallback, 'score' => 1];
            }
        }

        if ($bestScore <= 0) {
            return ['topic' => null, 'score' => $bestScore];
        }

        return ['topic' => $bestTopic, 'score' => $bestScore];
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

                $variantsForReply = $availableVariants->isNotEmpty()
                    ? $availableVariants
                    : $activeVariants;

                $sizes = $variantsForReply
                    ->pluck('size')
                    ->filter(fn ($size) => trim((string) $size) !== '')
                    ->values()
                    ->all();

                $matchesSize = $requestedSize === null
                    ? true
                    : $this->productMatchesRequestedSize($variantsForReply, $requestedSize);

                return [
                    'topic_product_id' => (int) $topicProduct->id,
                    'product_id' => (int) $product->id,
                    'title' => (string) $product->title,
                    'sku' => $product->sku ? (string) $product->sku : null,
                    'price' => $product->sale_price !== null ? (float) $product->sale_price : null,
                    'main_photo_url' => $this->absoluteUrl($product->main_photo_url),
                    'sizes' => $sizes,
                    'sort_order' => (int) $topicProduct->sort_order,
                    'is_active' => (bool) $topicProduct->is_active,
                    'matches_size' => $matchesSize,
                ];
            })
            ->sortBy([
                ['matches_size', 'desc'],
                ['sort_order', 'asc'],
                ['product_id', 'asc'],
            ])
            ->values();

        if ($requestedSize !== null) {
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
            $url = $this->absoluteUrl($mediaItem->url ?: $mediaItem->savedFile?->url);
            if (!$url) {
                continue;
            }

            $media->push([
                'source' => 'topic_media',
                'topic_media_id' => (int) $mediaItem->id,
                'media_type' => (string) $mediaItem->media_type,
                'label' => trim((string) $mediaItem->label),
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
                ->values();

            if ($topicMedia->isEmpty()) {
                $topicMedia = $topic->mediaItems->values();
            }

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

            if ($topicMedia->isNotEmpty()) {
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
        $visualPreferenceStems = $this->extractVisualPreferenceStems($query);
        $explicitOverviewRequest = str_contains($query, 'колаж')
            || str_contains($query, 'палiтр')
            || str_contains($query, 'палітр');
        $tokens = collect(preg_split('/[^[:alnum:]]+/u', $query))
            ->filter(fn ($token) => mb_strlen((string) $token) >= 4)
            ->values();

        $ranked = $media
            ->map(function (array $item) use ($query, $tokens, $visualPreferenceStems, $explicitOverviewRequest) {
                $label = $this->normalizeText((string) ($item['label'] ?? ''));
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

        if ($sendAll) {
            return $ranked;
        }

        $positive = $ranked
            ->filter(fn (array $item) => (int) ($item['score'] ?? 0) > 0)
            ->values();

        if ($positive->isNotEmpty()) {
            $specificMedia = $positive
                ->reject(fn (array $item) => in_array((string) ($item['media_type'] ?? ''), ['collage', 'palette'], true))
                ->values();

            return ($specificMedia->isNotEmpty() ? $specificMedia : $positive)
                ->take(3)
                ->values();
        }

        if ($visualPreferenceStems !== []) {
            return collect();
        }

        $specificMedia = $ranked
            ->reject(fn (array $item) => in_array((string) ($item['media_type'] ?? ''), ['collage', 'palette'], true))
            ->values();

        if ($specificMedia->isNotEmpty()) {
            return $specificMedia->take(3)->values();
        }

        return $explicitOverviewRequest
            ? $ranked->take(3)->values()
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
        ?ChatAiTopic $topic,
        Collection $products,
        Collection $selectedMedia,
        array $slotState,
        ?int $requestedSize,
        bool $isPhotoRequest,
        bool $isAllPhotosRequest,
        bool $shouldAskPhotoConfirmation
    ): array {
        $instructions = $this->buildSystemInstructions(
            $settings,
            $rules,
            $message,
            $slotState,
            $products,
            $requestedSize,
            $shouldAskPhotoConfirmation
        );
        $memory = $message->conversation
            ? $this->buildConversationMemoryBlock($message->conversation)
            : '';
        $history = $this->buildHistoryForPrompt(
            (int) $message->conversation_id,
            (int) ($settings['max_messages'] ?? 12)
        );
        $topicBlock = $this->buildTopicBlock(
            $topic,
            $products,
            $selectedMedia,
            $requestedSize,
            (string) ($message->text ?? ''),
            $isPhotoRequest,
            $isAllPhotosRequest
        );
        $slotBlock = $this->buildSlotStateBlock($slotState['definitions'], $slotState['slots'], $slotState['missing'], $slotState['next'], $slotState['order_ready']);

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
        ChatMessage $message,
        array $slotState,
        Collection $products,
        ?int $requestedSize,
        bool $shouldAskPhotoConfirmation
    ): string
    {
        $assistantName = trim((string) ($settings['assistant_name'] ?? 'DomCRM AI'));
        $replyStyle = trim((string) ($settings['reply_style'] ?? ''));
        $companyContext = trim((string) ($settings['company_context'] ?? ''));
        $knowledgeBase = trim((string) ($settings['knowledge_base'] ?? ''));
        $handoffRules = trim((string) ($settings['handoff_rules'] ?? ''));
        $qualificationFields = (array) ($settings['qualification_fields'] ?? []);
        $flowGuidance = $this->buildFlowGuidanceBlock(
            $message,
            $slotState,
            $products,
            $requestedSize,
            $shouldAskPhotoConfirmation
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
            'Якщо даних недостатньо — прямо напиши про це і постав 1 уточнювальне питання.',
            'Фото надсилаються окремо системою. У тексті не вставляй URL.',
            'Якщо клієнт просить фото і в контексті медіа відсутні — коротко повідом, що немає підготовлених фото, і запропонуй близьку тему або менеджера.',
            'Якщо клієнт просить показати всі варіанти — у тексті підтвердь, що показуєш всі доступні для поточної теми.',
            'Якщо клієнт просить конкретний колір або варіант, а точних медіа в контексті немає, не пиши, що надсилаєш його фото.',
            'У блоці "Стан слотів замовлення" вже є зібрані поля. Не перепитуй те, що вже заповнено.',
            'Якщо система вказала "Наступний слот для уточнення", орієнтуйся на нього як на пріоритет і не перепитуй уже зібрані поля.',
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
        ChatMessage $message,
        array $slotState,
        Collection $products,
        ?int $requestedSize,
        bool $shouldAskPhotoConfirmation
    ): string {
        $guidance = [];
        $text = (string) ($message->text ?? '');
        $nextSlot = is_string($slotState['next'] ?? null) ? $slotState['next'] : null;

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

        if ($missing === []) {
            return false;
        }

        if (
            !$this->hasSlotValue($slots['model'] ?? null, 'model')
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

        $slotSummary = trim((string) ($ai['slot_summary'] ?? ''));
        if ($slotSummary !== '') {
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
     *     just_completed: bool
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
        $previousNextSlot = data_get($conversation->meta, 'ai.next_slot');

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
            $previousNextSlot
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
        $nextSlot = $missing[0] ?? null;
        $orderReady = $missing === [];

        return [
            'definitions' => $definitions,
            'slots' => $slots,
            'missing' => $missing,
            'next' => $nextSlot,
            'order_ready' => $orderReady,
            'summary' => $this->buildSlotSummary($definitions, $slots, $missing, $nextSlot, $orderReady),
            'updated' => $updated,
            'updated_keys' => array_keys($updated),
            'just_completed' => !$previousOrderReady && $orderReady,
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

        $lastTopicName = trim((string) ($ai['last_topic_name'] ?? ''));
        if ($lastTopicName !== '' && !$this->hasSlotValue($slots['model'] ?? null, 'model')) {
            $slots['model'] = $lastTopicName;
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
        ?string $previousNextSlot
    ): array {
        $text = trim((string) ($message->text ?? ''));
        if ($text === '') {
            return [];
        }

        $updates = [];

        if ($topic !== null && ($topicScore > 0 || $products->isNotEmpty() || !$this->hasSlotValue($currentSlots['model'] ?? null, 'model'))) {
            $updates['model'] = $topic->name;
        }

        if ($requestedSize !== null && $this->shouldPersistRequestedSizeAsSlot($text, $previousNextSlot)) {
            $updates['size'] = $requestedSize;
        }

        if ($color = $this->extractColorValue($text, $previousNextSlot)) {
            $updates['color'] = $color;
        }

        if ($quantity = $this->extractQuantityValue($text, $previousNextSlot)) {
            $updates['quantity'] = $quantity;
        }

        if ($city = $this->extractCityValue($text, $previousNextSlot)) {
            $updates['city'] = $city;
        }

        if ($delivery = $this->extractDeliveryValue($text, $previousNextSlot)) {
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
            (bool) preg_match('/(післяплат|накладен|налож)/u', $normalized) => 'Післяплата',
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

        if ($previousNextSlot === 'quantity' && preg_match('/^\s*([1-9]\d?)\s*$/u', $normalized, $match)) {
            return (int) $match[1];
        }

        return null;
    }

    private function extractCityValue(string $text, ?string $previousNextSlot): ?string
    {
        $trimmed = trim((string) preg_replace('/\s+/u', ' ', $text));
        $normalizedTrimmed = $this->normalizeText($trimmed);

        if (preg_match('/^([^,]{2,50}),\s*(?:нова пошта|відділен|поштомат)/iu', $trimmed, $match)) {
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

    private function extractColorValue(string $text, ?string $previousNextSlot): ?string
    {
        $normalized = $this->normalizeText($text);
        $colorMap = $this->colorStemMap();

        foreach ($colorMap as $needle => $label) {
            if (str_contains($normalized, $needle)) {
                return $label;
            }
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

    /**
     * @return array<string, string>
     */
    private function colorStemMap(): array
    {
        return [
            'біл' => 'Білий',
            'чорн' => 'Чорний',
            'сір' => 'Сірий',
            'рожев' => 'Рожевий',
            'блакит' => 'Блакитний',
            'син' => 'Синій',
            'червон' => 'Червоний',
            'коричн' => 'Коричневий',
            'беж' => 'Бежевий',
            'молоч' => 'Молочний',
            'пудр' => 'Пудровий',
            'малин' => 'Малиновий',
            'електрик' => 'Електрик',
            'капучин' => 'Капучино',
            'зелен' => 'Зелений',
        ];
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

        return (bool) preg_match('/(\bсм\b|сантим|стоп|устілк|довжин|на ногу|по нозі|по стопі|маломір)/u', $normalized);
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
        bool $orderReady
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
        bool $orderReady
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

    private function normalizeSizeSlotValue(mixed $value): ?int
    {
        $number = (int) $value;

        return $number >= 20 && $number <= 55 ? $number : null;
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
        ?ChatAiTopic $topic,
        Collection $products,
        Collection $selectedMedia,
        ?int $requestedSize,
        string $messageText,
        bool $isPhotoRequest,
        bool $isAllPhotosRequest
    ): string {
        $productsText = $products
            ->take(20)
            ->map(function (array $product, int $index) {
                $sizeLines = collect((array) ($product['sizes'] ?? []))
                    ->filter(fn ($size) => trim((string) $size) !== '')
                    ->map(fn ($size) => '   - ' . trim((string) $size))
                    ->implode("\n");
                $price = $product['price'] !== null
                    ? number_format((float) $product['price'], 0, '.', '') . ' грн'
                    : 'ціна не вказана';

                return ($index + 1) . '. '
                    . ($product['title'] ?? 'Товар')
                    . ($product['sku'] ? " (SKU: {$product['sku']})" : '')
                    . " — {$price}"
                    . "\n   розміри:\n"
                    . ($sizeLines !== '' ? $sizeLines : '   - не вказано');
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

        return trim(implode("\n", array_filter([
            $topic ? "Поточна тема: {$topic->name}" : 'Поточна тема: не визначена',
            $topic && trim((string) $topic->instruction) !== '' ? "Інструкція теми: {$topic->instruction}" : null,
            $this->buildRequestedSizeContextLine($requestedSize, $messageText),
            $isPhotoRequest ? 'Клієнт просить фото: так' : 'Клієнт просить фото: ні',
            $isAllPhotosRequest ? 'Клієнт просить показати всі фото: так' : null,
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

    private function buildUnknownTopicStatusNote(int $sentMediaCount): string
    {
        if ($sentMediaCount > 0) {
            return "Тема не визначена, AI надіслав {$sentMediaCount} медіа для вибору моделі.";
        }

        return 'Тема не визначена, AI попросив клієнта уточнити модель або категорію.';
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
            '/(покажи|покажiть|покажіть|показат|побачити|скинь|надішли|надiшли)/u',
            $normalized
        );
        $hasVisualObject = (bool) preg_match(
            '/(кольор|кольори|біл|чорн|сір|рожев|блакит|коричн|червон|малинов|електрик|капучин|модел|варіант|асортимент|в наявност|які є|якi є|яки є|що є|вигляд|вигляда)/u',
            $normalized
        );

        return $hasShowVerb && $hasVisualObject;
    }

    private function isAllPhotosRequest(string $text): bool
    {
        $normalized = $this->normalizeText($text);
        if ($normalized === '') {
            return false;
        }

        if ($this->extractVisualPreferenceStems($normalized) !== []) {
            return false;
        }

        return (bool) preg_match(
            '/(всi|всі|усi|усі|все|усе|які є|якi є|яки є|що є|в наявностi|в наявності|весь асортимент|всі варіанти|усі варіанти|всі кольори|усі кольори)/u',
            $normalized
        );
    }

    private function isBroadCatalogRequest(string $normalizedText): bool
    {
        if ($normalizedText === '') {
            return false;
        }

        return (bool) preg_match(
            '/(які маєте|якi маєте|яки маєте|що маєте|які у вас є|якi у вас є|яки у вас є|що у вас є|які є|якi є|яки є|що є|в наявност|асортимент)/u',
            $normalizedText
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
        bool $forceContext
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
