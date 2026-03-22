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

        $requestedSize = $this->extractRequestedSize($message->text);
        $topicMatch = $this->matchTopic(
            $topics,
            (string) ($message->text ?? ''),
            $conversation
        );
        $topic = $topicMatch['topic'];
        $isTopicUnclear = $topic === null;

        $products = $topic ? $this->resolveTopicProducts($topic, $requestedSize) : collect();
        $mediaCandidates = $topic ? $this->resolveTopicMedia($topic, $products) : collect();
        $isPhotoRequest = $this->isPhotoRequest((string) ($message->text ?? ''));
        $isAllPhotosRequest = $isPhotoRequest && $this->isAllPhotosRequest((string) ($message->text ?? ''));
        $selectedMedia = $isTopicUnclear
            ? $this->resolveAllTopicsOverviewMedia($topics)
            : ($isPhotoRequest
                ? $this->selectMediaForReply($mediaCandidates, (string) ($message->text ?? ''), $isAllPhotosRequest)
                : collect());

        try {
            $reply = $this->buildReply(
                $message,
                $settings,
                $rules,
                $topic,
                $products,
                $selectedMedia,
                $requestedSize,
                $isPhotoRequest,
                $isAllPhotosRequest
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

        $sentMediaCount = 0;
        if (($isPhotoRequest || $isTopicUnclear) && $selectedMedia->isNotEmpty()) {
            $sentMediaCount = $this->sendMediaMessages($conversation, $selectedMedia);
        }

        $sentText = false;
        $replyText = trim((string) ($reply['reply_text'] ?? ''));
        if ($replyText !== '') {
            $sentText = $this->sendTextMessage($conversation, $replyText);
        }

        $handoff = (bool) ($reply['handoff'] ?? false);
        if ($handoff) {
            $handoffReason = trim((string) ($reply['handoff_reason'] ?? ''));
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
            $statusNote = $isTopicUnclear
                ? $this->buildUnknownTopicStatusNote($sentMediaCount)
                : $this->buildRuntimeStatusNote(
                    $topic,
                    $requestedSize,
                    $isPhotoRequest,
                    $sentMediaCount
                );

            $this->syncConversationAiContext($conversation, $topic, [
                'status_code' => $isTopicUnclear ? 'topic_overview' : 'replied',
                'status_note' => $statusNote,
                'last_error' => null,
                'last_requested_size' => $requestedSize,
                'last_photo_request' => $isPhotoRequest,
                'last_all_photo_request' => $isAllPhotosRequest,
                'topic_unresolved' => $isTopicUnclear,
            ]);
        }

        $this->markMessageAiState($message, [
            'status' => 'done',
            'topic_id' => $topic?->id,
            'topic_name' => $topic?->name,
            'requested_size' => $requestedSize,
            'photo_request' => $isPhotoRequest,
            'all_photo_request' => $isAllPhotosRequest,
            'sent_media_count' => $sentMediaCount,
            'sent_text' => $sentText,
            'handoff' => $handoff,
            'handoff_reason' => trim((string) ($reply['handoff_reason'] ?? '')),
            'topic_unresolved' => $isTopicUnclear,
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
        $lastTopicId = (int) data_get($conversation->meta, 'ai.last_topic_id', 0);

        $bestTopic = null;
        $bestScore = PHP_INT_MIN;

        foreach ($topics as $topic) {
            $score = 0;

            if ($lastTopicId > 0 && $lastTopicId === (int) $topic->id) {
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
        $tokens = collect(preg_split('/[^[:alnum:]]+/u', $query))
            ->filter(fn ($token) => mb_strlen((string) $token) >= 4)
            ->values();

        $ranked = $media
            ->map(function (array $item) use ($query, $tokens) {
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

                if (
                    in_array(($item['media_type'] ?? ''), ['collage', 'palette'], true)
                    && (str_contains($query, 'колаж') || str_contains($query, 'палiтр') || str_contains($query, 'палітр'))
                ) {
                    $score += 40;
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

        return $ranked->take(3)->values();
    }

    /**
     * @param  Collection<int, ChatAiResponseRule>  $rules
     * @param  Collection<int, array<string, mixed>>  $products
     * @param  Collection<int, array<string, mixed>>  $selectedMedia
     * @return array{reply_text: string, handoff: bool, handoff_reason: string}
     */
    private function buildReply(
        ChatMessage $message,
        array $settings,
        Collection $rules,
        ?ChatAiTopic $topic,
        Collection $products,
        Collection $selectedMedia,
        ?int $requestedSize,
        bool $isPhotoRequest,
        bool $isAllPhotosRequest
    ): array {
        $instructions = $this->buildSystemInstructions($settings, $rules);
        $history = $this->buildHistoryForPrompt(
            (int) $message->conversation_id,
            (int) ($settings['max_messages'] ?? 12)
        );
        $topicBlock = $this->buildTopicBlock($topic, $products, $selectedMedia, $requestedSize, $isPhotoRequest, $isAllPhotosRequest);

        $input = implode("\n\n", array_filter([
            'Останнє повідомлення клієнта: ' . trim((string) ($message->text ?? '')),
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
    private function buildSystemInstructions(array $settings, Collection $rules): string
    {
        $assistantName = trim((string) ($settings['assistant_name'] ?? 'DomCRM AI'));
        $replyStyle = trim((string) ($settings['reply_style'] ?? ''));
        $companyContext = trim((string) ($settings['company_context'] ?? ''));
        $knowledgeBase = trim((string) ($settings['knowledge_base'] ?? ''));
        $handoffRules = trim((string) ($settings['handoff_rules'] ?? ''));
        $qualificationFields = (array) ($settings['qualification_fields'] ?? []);

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
            $replyStyle !== '' ? "Стиль відповіді: {$replyStyle}" : null,
            $companyContext !== '' ? "Контекст компанії: {$companyContext}" : null,
            $knowledgeBase !== '' ? "Додаткова база знань: {$knowledgeBase}" : null,
            $qualificationText !== '' ? "Поля кваліфікації, які треба зібрати по діалогу: {$qualificationText}" : null,
            $handoffRules !== '' ? "Коли передавати менеджеру:\n{$handoffRules}" : null,
            $rulesBlock !== '' ? "Сценарні правила:\n{$rulesBlock}" : null,
        ])));
    }

    private function buildHistoryForPrompt(int $conversationId, int $maxMessages): string
    {
        $history = ChatMessage::query()
            ->with('attachments')
            ->where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->limit(max(4, min(30, $maxMessages)))
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

    /**
     * @param  Collection<int, array<string, mixed>>  $products
     * @param  Collection<int, array<string, mixed>>  $selectedMedia
     */
    private function buildTopicBlock(
        ?ChatAiTopic $topic,
        Collection $products,
        Collection $selectedMedia,
        ?int $requestedSize,
        bool $isPhotoRequest,
        bool $isAllPhotosRequest
    ): string {
        $productsText = $products
            ->take(20)
            ->map(function (array $product, int $index) {
                $sizes = implode(', ', array_filter((array) ($product['sizes'] ?? [])));
                $price = $product['price'] !== null
                    ? number_format((float) $product['price'], 0, '.', '') . ' грн'
                    : 'ціна не вказана';

                return ($index + 1) . '. '
                    . ($product['title'] ?? 'Товар')
                    . ($product['sku'] ? " (SKU: {$product['sku']})" : '')
                    . " — {$price}"
                    . ($sizes !== '' ? "; розміри: {$sizes}" : '; розміри: не вказано');
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
            $requestedSize ? "Запитаний розмір: {$requestedSize}" : 'Запитаний розмір: не вказаний',
            $isPhotoRequest ? 'Клієнт просить фото: так' : 'Клієнт просить фото: ні',
            $isAllPhotosRequest ? 'Клієнт просить показати всі фото: так' : null,
            $productsText !== '' ? "Релевантні товари:\n{$productsText}" : 'Релевантні товари: немає',
            $mediaText !== '' ? "Медіа, які система може надіслати зараз:\n{$mediaText}" : 'Медіа для поточної теми: немає',
        ])));
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
        $ai['last_topic_id'] = $topic?->id;
        $ai['last_topic_name'] = $topic?->name;

        foreach ($contextMeta as $key => $value) {
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

        return (bool) preg_match(
            '/(фото|фотк|покажи|покажiть|покажіть|побачити|скинь|надішли|надiшли|картин|зображ|колаж|палiтр|палітр)/u',
            $normalized
        );
    }

    private function isAllPhotosRequest(string $text): bool
    {
        $normalized = $this->normalizeText($text);

        return (bool) preg_match(
            '/(всi|всі|усi|усі|все|усе|які є|якi є|в наявностi|в наявності|весь асортимент)/u',
            $normalized
        );
    }

    private function extractRequestedSize(?string $text): ?int
    {
        $normalized = $this->normalizeText((string) $text);
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/(?<!\d)([2-5]\d)(?!\d)/u', $normalized, $match)) {
            $size = (int) $match[1];
            if ($size >= 20 && $size <= 55) {
                return $size;
            }
        }

        return null;
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
