<?php

namespace App\Services;

use App\Models\ChatAiAgent;
use App\Models\ChatAiConversationState;
use App\Models\ChatAiEvent;
use App\Models\ChatAiPromptVersion;
use App\Models\ChatAiRun;
use App\Models\ChatContact;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Color;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChatAiOrchestratorService
{
    private const STAGE_INTEREST = 'interest';
    private const STAGE_SELECTION = 'selection';
    private const STAGE_CHECKOUT_READY = 'checkout_ready';
    private const STAGE_CHECKOUT = 'checkout';
    private const STAGE_ORDER = [
        self::STAGE_INTEREST => 1,
        self::STAGE_SELECTION => 2,
        self::STAGE_CHECKOUT_READY => 3,
        self::STAGE_CHECKOUT => 4,
    ];

    public function __construct(
        private readonly ChatService $chatService,
        private readonly MetaService $metaService
    ) {
    }

    public function handleInboundMessage(
        ChatConversation $conversation,
        ChatMessage $inboundMessage,
        Customer $customer,
        ChatContact $contact,
        string $platform
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        // Не блокуємо AI тільки через assigned_user_id.
        // Блокуємо лише коли оператор щойно відповідав вручну.
        if ($this->hasRecentOperatorActivity($conversation->id)) {
            return;
        }

        $inputText = trim((string) $inboundMessage->text);
        if ($inputText === '' && !$inboundMessage->attachments()->exists()) {
            return;
        }

        $apiKey = trim((string) config('services.openai.api_key'));
        if ($apiKey === '') {
            Log::warning('Chat AI: OPENAI_API_KEY відсутній, авто-відповідь пропущено.', [
                'conversation_id' => $conversation->id,
                'message_id' => $inboundMessage->id,
            ]);

            return;
        }

        $agent = $this->resolveAgent();
        if (!$agent) {
            Log::warning('Chat AI: активний агент не знайдений.', [
                'conversation_id' => $conversation->id,
                'agent_code' => config('services.chat_ai.default_agent_code'),
            ]);

            return;
        }

        $state = $this->resolveState($conversation, $agent, $inboundMessage->id);
        $promptVersion = $this->resolvePromptVersion($agent, $state->stage);
        if (!$promptVersion) {
            Log::warning('Chat AI: prompt version не знайдена для stage.', [
                'conversation_id' => $conversation->id,
                'agent_id' => $agent->id,
                'stage' => $state->stage,
            ]);

            return;
        }

        $history = $this->loadHistory($conversation->id);
        $messages = $this->buildModelMessages(
            $history,
            $promptVersion,
            $state,
            $conversation,
            $platform
        );
        $inputChars = $this->countInputChars($messages);
        $startedAtTs = microtime(true);
        $startedAt = now();
        $stageBefore = $state->stage;

        $run = ChatAiRun::create([
            'conversation_id' => $conversation->id,
            'state_id' => $state->id,
            'source_message_id' => $inboundMessage->id,
            'agent_id' => $agent->id,
            'prompt_version_id' => $promptVersion->id,
            'stage_snapshot' => $state->stage,
            'status' => 'running',
            'provider' => $agent->provider ?: 'openai',
            'model' => $agent->model ?: (string) config('services.openai.model', 'gpt-4.1-mini'),
            'input_messages' => count($messages),
            'input_chars' => $inputChars,
            'started_at' => $startedAt,
        ]);

        try {
            [$rawOutput, $usage] = $this->callOpenAi($messages, $agent);
            $normalized = $this->normalizeModelPayload($this->decodeModelJson($rawOutput));

            $slotPatch = $this->buildSlotPatch($state, $normalized, $inputText);
            $nextStage = $this->resolveNextStage($stageBefore, $normalized['stage'], $slotPatch);
            $reply = $this->buildSafeReply($normalized['reply'], $nextStage, $slotPatch);

            if ($reply === '') {
                throw new \RuntimeException('Chat AI: порожня відповідь моделі після санітизації.');
            }

            $metaResult = $this->metaService->sendMessage(
                $customer,
                $reply,
                [],
                $platform,
                $contact->external_user_id
            );

            if (!$metaResult) {
                throw new \RuntimeException('Chat AI: Meta API повернув помилку при відправці.');
            }

            $outboundMessage = $this->chatService->storeMessage($conversation, [
                'direction' => 'outbound',
                'message_type' => 'text',
                'external_message_id' => $metaResult['message_id'] ?? null,
                'delivery_status' => 'sent',
                'source' => 'system',
                'text' => $reply,
                'meta' => [
                    'ai' => [
                        'agent_id' => $agent->id,
                        'agent_code' => $agent->code,
                        'run_id' => $run->id,
                        'stage' => $nextStage,
                    ],
                ],
                'sent_at' => now(),
            ]);

            $this->chatService->updateConversationAfterMessage($conversation, $outboundMessage, false);

            $stageChanged = $nextStage !== $stageBefore;
            $stateUpdate = [
                'agent_id' => $agent->id,
                'stage' => $nextStage,
                'last_intent' => $normalized['last_intent'],
                'intent_purchase' => (bool) $slotPatch['intent_purchase'],
                'requires_human' => (bool) ($state->requires_human || $normalized['requires_human']),
                'slots_json' => $slotPatch['slots_json'],
                'missing_slots_json' => $slotPatch['missing_slots_json'],
                'selected_product_id' => $slotPatch['selected_product_id'],
                'selected_variant_id' => $slotPatch['selected_variant_id'],
                'selected_color_id' => $slotPatch['selected_color_id'],
                'selected_size' => $slotPatch['selected_size'],
                'last_customer_message_id' => $inboundMessage->id,
                'last_agent_message_id' => $outboundMessage->id,
                'turn_count' => (int) $state->turn_count + 1,
            ];

            if ($stageChanged) {
                $stateUpdate['stage_updated_at'] = now();
            }

            $state->fill($stateUpdate);
            $state->save();

            $latencyMs = (int) round((microtime(true) - $startedAtTs) * 1000);
            $run->update([
                'status' => 'completed',
                'output_text' => $rawOutput,
                'output_chars' => mb_strlen($reply),
                'prompt_tokens' => $usage['prompt_tokens'],
                'completion_tokens' => $usage['completion_tokens'],
                'total_tokens' => $usage['total_tokens'],
                'latency_ms' => $latencyMs,
                'meta_json' => [
                    'stage_before' => $stageBefore,
                    'stage_after' => $nextStage,
                    'missing_slots' => $slotPatch['missing_slots_json'],
                ],
                'finished_at' => now(),
            ]);

            if ($stageChanged) {
                $this->logEvent($conversation->id, $state->id, $run->id, 'stage_changed', $stageBefore, $nextStage, [
                    'reason' => 'slots_and_intent',
                ]);
            }

            $this->logEvent($conversation->id, $state->id, $run->id, 'reply_sent', $stageBefore, $nextStage, [
                'message_id' => $outboundMessage->id,
            ]);

            $this->updateConversationMeta($conversation, $agent->code, $nextStage, $state->id, $run->id);
        } catch (\Throwable $e) {
            $state->requires_human = true;
            $state->last_customer_message_id = $inboundMessage->id;
            $state->save();

            $latencyMs = (int) round((microtime(true) - $startedAtTs) * 1000);
            $run->update([
                'status' => 'failed',
                'error_code' => 'ai_orchestration_failed',
                'error_message' => Str::limit($e->getMessage(), 900),
                'latency_ms' => $latencyMs,
                'finished_at' => now(),
            ]);

            $this->logEvent($conversation->id, $state->id, $run->id, 'reply_failed', $stageBefore, $state->stage, [
                'error' => Str::limit($e->getMessage(), 400),
            ]);

            Log::error('Chat AI orchestration failed', [
                'conversation_id' => $conversation->id,
                'message_id' => $inboundMessage->id,
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function isEnabled(): bool
    {
        return (bool) config('services.chat_ai.enabled', true);
    }

    private function resolveAgent(): ?ChatAiAgent
    {
        $code = (string) config('services.chat_ai.default_agent_code', 'sales_assistant_v1');

        return ChatAiAgent::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->first();
    }

    private function resolveState(
        ChatConversation $conversation,
        ChatAiAgent $agent,
        int $lastCustomerMessageId
    ): ChatAiConversationState {
        $state = ChatAiConversationState::query()
            ->firstOrCreate(
                ['conversation_id' => $conversation->id],
                [
                    'agent_id' => $agent->id,
                    'stage' => self::STAGE_INTEREST,
                    'last_customer_message_id' => $lastCustomerMessageId,
                    'stage_updated_at' => now(),
                ]
            );

        if ($state->agent_id !== $agent->id) {
            $state->agent_id = $agent->id;
            $state->save();
        }

        if (!isset(self::STAGE_ORDER[$state->stage])) {
            $state->stage = self::STAGE_INTEREST;
            $state->stage_updated_at = now();
            $state->save();
        }

        return $state;
    }

    private function resolvePromptVersion(ChatAiAgent $agent, string $stage): ?ChatAiPromptVersion
    {
        $prompt = ChatAiPromptVersion::query()
            ->where('agent_id', $agent->id)
            ->where('stage', $stage)
            ->where('is_current', true)
            ->latest('id')
            ->first();

        if ($prompt) {
            return $prompt;
        }

        return ChatAiPromptVersion::query()
            ->where('agent_id', $agent->id)
            ->where('stage', self::STAGE_INTEREST)
            ->where('is_current', true)
            ->latest('id')
            ->first();
    }

    /**
     * @return array<int, array{role:string,content:string}>
     */
    private function buildModelMessages(
        \Illuminate\Support\Collection $history,
        ChatAiPromptVersion $promptVersion,
        ChatAiConversationState $state,
        ChatConversation $conversation,
        string $platform
    ): array {
        $messages = [];

        $messages[] = [
            'role' => 'system',
            'content' => $this->buildSystemPrompt($promptVersion),
        ];

        $messages[] = [
            'role' => 'system',
            'content' => $this->buildStateContext($state, $conversation, $platform, $promptVersion),
        ];

        foreach ($history as $message) {
            /** @var ChatMessage $message */
            $content = $this->formatHistoryMessage($message);
            if ($content === '') {
                continue;
            }

            $messages[] = [
                'role' => $message->direction === 'inbound' ? 'user' : 'assistant',
                'content' => $content,
            ];
        }

        return $messages;
    }

    private function buildSystemPrompt(ChatAiPromptVersion $promptVersion): string
    {
        $policy = $promptVersion->policy_json;
        $policyJson = is_array($policy)
            ? json_encode($policy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '{}';

        return trim($promptVersion->system_prompt) . "\n\n"
            . "Працюй тільки українською мовою.\n"
            . "Відповідь повертай ТІЛЬКИ у JSON без markdown.\n"
            . "Схема JSON:\n"
            . "{\n"
            . "  \"reply\": \"string\",\n"
            . "  \"stage\": \"interest|selection|checkout_ready|checkout\",\n"
            . "  \"last_intent\": \"string|null\",\n"
            . "  \"intent_purchase\": true|false,\n"
            . "  \"requires_human\": true|false,\n"
            . "  \"selected_size\": \"string|null\",\n"
            . "  \"selected_color\": \"string|null\",\n"
            . "  \"selected_product_id\": number|null,\n"
            . "  \"selected_variant_id\": number|null,\n"
            . "  \"missing_slots\": [\"selected_product\",\"selected_size\",\"selected_variant\",\"purchase_intent\",\"name\",\"phone\",\"city\",\"warehouse\"],\n"
            . "  \"delivery_fields\": {\"name\": \"string|null\", \"phone\": \"string|null\", \"city\": \"string|null\", \"warehouse\": \"string|null\"}\n"
            . "}\n"
            . "Не запитуй дані доставки (ПІБ, телефон, місто, відділення/поштомат), доки stage не дійшов до checkout_ready.\n"
            . "Одне уточнююче питання за раз.\n"
            . "policy_json=" . $policyJson;
    }

    private function buildStateContext(
        ChatAiConversationState $state,
        ChatConversation $conversation,
        string $platform,
        ChatAiPromptVersion $promptVersion
    ): string {
        $context = [
            'conversation_id' => $conversation->id,
            'platform' => $platform,
            'current_stage' => $state->stage,
            'intent_purchase' => (bool) $state->intent_purchase,
            'selected_product_id' => $state->selected_product_id,
            'selected_variant_id' => $state->selected_variant_id,
            'selected_color_id' => $state->selected_color_id,
            'selected_size' => $state->selected_size,
            'slots' => $state->slots_json ?? [],
            'missing_slots' => $state->missing_slots_json ?? [],
            'policy_json' => $promptVersion->policy_json ?? [],
        ];

        return 'Контекст діалогу: ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function formatHistoryMessage(ChatMessage $message): string
    {
        $text = trim((string) $message->text);
        if ($text !== '') {
            return Str::limit($text, 1200);
        }

        $type = $message->message_type ?: 'message';

        return match ($type) {
            'image' => '[надіслано зображення]',
            'video' => '[надіслано відео]',
            'audio' => '[надіслано аудіо]',
            'file' => '[надіслано файл]',
            default => '[службове повідомлення]',
        };
    }

    /**
     * @return array{0:string,1:array{prompt_tokens:?int,completion_tokens:?int,total_tokens:?int}}
     */
    private function callOpenAi(array $messages, ChatAiAgent $agent): array
    {
        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        $timeout = max(5, (int) config('services.openai.timeout', 30));
        $apiKey = (string) config('services.openai.api_key');

        $payload = [
            'model' => (string) ($agent->model ?: config('services.openai.model', 'gpt-4.1-mini')),
            'messages' => $messages,
            'temperature' => (float) ($agent->temperature ?? 0.3),
            'max_tokens' => max(60, (int) ($agent->max_output_tokens ?? 300)),
            'response_format' => ['type' => 'json_object'],
        ];

        if ((bool) config('services.openai.store', false)) {
            $payload['store'] = true;
        }

        $response = $this->performOpenAiRequest($baseUrl, $apiKey, $timeout, $payload);

        if ($response->failed()) {
            $errorBody = (string) $response->body();
            $supportsFallback = str_contains(mb_strtolower($errorBody), 'response_format')
                || str_contains(mb_strtolower($errorBody), 'json_object');

            if ($supportsFallback) {
                unset($payload['response_format']);
                $response = $this->performOpenAiRequest($baseUrl, $apiKey, $timeout, $payload);
            }
        }

        if ($response->failed()) {
            throw new \RuntimeException(
                'OpenAI HTTP ' . $response->status() . ': ' . Str::limit((string) $response->body(), 500)
            );
        }

        $json = $response->json();
        $content = data_get($json, 'choices.0.message.content');
        $raw = $this->normalizeOpenAiContent($content);

        if (trim($raw) === '') {
            throw new \RuntimeException('OpenAI повернув порожній content.');
        }

        return [
            $raw,
            [
                'prompt_tokens' => $this->nullableInt(data_get($json, 'usage.prompt_tokens')),
                'completion_tokens' => $this->nullableInt(data_get($json, 'usage.completion_tokens')),
                'total_tokens' => $this->nullableInt(data_get($json, 'usage.total_tokens')),
            ],
        ];
    }

    private function performOpenAiRequest(
        string $baseUrl,
        string $apiKey,
        int $timeout,
        array $payload
    ): \Illuminate\Http\Client\Response {
        return Http::withToken($apiKey)
            ->acceptJson()
            ->timeout($timeout)
            ->post($baseUrl . '/chat/completions', $payload);
    }

    private function normalizeOpenAiContent(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }

        if (!is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $item) {
            if (is_string($item)) {
                $parts[] = $item;
                continue;
            }

            if (is_array($item)) {
                $text = $item['text'] ?? ($item['content'] ?? null);
                if (is_string($text) && $text !== '') {
                    $parts[] = $text;
                }
            }
        }

        return trim(implode("\n", $parts));
    }

    private function decodeModelJson(string $raw): array
    {
        $trimmed = trim($raw);
        $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $trimmed, $matches) === 1) {
            $decoded = json_decode((string) $matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        throw new \RuntimeException('Відповідь моделі не є валідним JSON.');
    }

    private function normalizeModelPayload(array $payload): array
    {
        $delivery = $payload['delivery_fields'] ?? [];
        if (!is_array($delivery)) {
            $delivery = [];
        }

        $missingSlots = $payload['missing_slots'] ?? [];
        if (!is_array($missingSlots)) {
            $missingSlots = [];
        }

        return [
            'reply' => trim((string) ($payload['reply'] ?? $payload['response'] ?? '')),
            'stage' => $this->normalizeStage($payload['stage'] ?? null),
            'last_intent' => $this->normalizeIntent($payload['last_intent'] ?? null),
            'intent_purchase' => $this->toBool($payload['intent_purchase'] ?? $payload['purchase_intent'] ?? false),
            'requires_human' => $this->toBool($payload['requires_human'] ?? false),
            'selected_size' => $this->cleanNullableString($payload['selected_size'] ?? $payload['size'] ?? null),
            'selected_color' => $this->cleanNullableString($payload['selected_color'] ?? $payload['color'] ?? null),
            'selected_product_id' => $this->nullableInt($payload['selected_product_id'] ?? $payload['product_id'] ?? null),
            'selected_variant_id' => $this->nullableInt($payload['selected_variant_id'] ?? $payload['variant_id'] ?? null),
            'missing_slots' => array_values(array_unique(array_filter(array_map(
                fn ($slot) => $this->cleanNullableString($slot),
                $missingSlots
            )))),
            'delivery_fields' => [
                'name' => $this->cleanNullableString($delivery['name'] ?? null),
                'phone' => $this->cleanNullableString($delivery['phone'] ?? null),
                'city' => $this->cleanNullableString($delivery['city'] ?? null),
                'warehouse' => $this->cleanNullableString($delivery['warehouse'] ?? null),
            ],
        ];
    }

    private function buildSlotPatch(ChatAiConversationState $state, array $normalized, string $inputText): array
    {
        $slots = is_array($state->slots_json) ? $state->slots_json : [];
        $delivery = is_array($slots['delivery'] ?? null) ? $slots['delivery'] : [];
        $incomingDelivery = $normalized['delivery_fields'] ?? [];

        foreach (['name', 'phone', 'city', 'warehouse'] as $field) {
            $value = $this->cleanNullableString($incomingDelivery[$field] ?? null);
            if ($value !== null) {
                $delivery[$field] = $value;
            }
        }

        if ($delivery !== []) {
            $slots['delivery'] = $delivery;
        }

        $selectedProductId = $state->selected_product_id;
        $selectedVariantId = $state->selected_variant_id;
        $selectedColorId = $state->selected_color_id;
        $selectedSize = $state->selected_size;

        $candidateProductId = $this->nullableInt($normalized['selected_product_id']);
        if ($candidateProductId && Product::query()->whereKey($candidateProductId)->exists()) {
            $selectedProductId = $candidateProductId;
        }

        $candidateVariantId = $this->nullableInt($normalized['selected_variant_id']);
        if ($candidateVariantId) {
            $variant = ProductVariant::query()
                ->select(['id', 'product_id', 'size'])
                ->find($candidateVariantId);

            if ($variant) {
                $selectedVariantId = $variant->id;
                $selectedProductId = $variant->product_id ?: $selectedProductId;
                if (!$selectedSize && $variant->size) {
                    $selectedSize = (string) $variant->size;
                }
            }
        }

        $candidateSize = $this->normalizeSize($normalized['selected_size'])
            ?: $this->extractSizeFromText($inputText);
        if ($candidateSize !== null) {
            $selectedSize = $candidateSize;
        }

        $resolvedColorId = $this->resolveColorId(
            $normalized['selected_color'] ?? null,
            $inputText
        );
        if ($resolvedColorId !== null) {
            $selectedColorId = $resolvedColorId;
        }

        $intentPurchase = (bool) (
            $state->intent_purchase
            || $normalized['intent_purchase']
            || $this->detectPurchaseIntent($inputText)
        );

        $missingSlots = $normalized['missing_slots'];
        if ($missingSlots === []) {
            $missingSlots = $this->calculateMissingSlots([
                'selected_product_id' => $selectedProductId,
                'selected_variant_id' => $selectedVariantId,
                'selected_size' => $selectedSize,
                'intent_purchase' => $intentPurchase,
                'delivery' => $delivery,
            ]);
        }

        return [
            'slots_json' => $slots,
            'missing_slots_json' => $missingSlots,
            'selected_product_id' => $selectedProductId,
            'selected_variant_id' => $selectedVariantId,
            'selected_color_id' => $selectedColorId,
            'selected_size' => $selectedSize,
            'intent_purchase' => $intentPurchase,
            'delivery_complete' => $this->isDeliveryComplete($delivery),
        ];
    }

    private function resolveNextStage(string $currentStage, ?string $modelStage, array $slotPatch): string
    {
        $stage = isset(self::STAGE_ORDER[$currentStage]) ? $currentStage : self::STAGE_INTEREST;

        $hasSelection = (bool) (
            $slotPatch['selected_size']
            || $slotPatch['selected_product_id']
            || $slotPatch['selected_variant_id']
            || $slotPatch['selected_color_id']
        );
        $hasCheckoutPrerequisites = (bool) (
            $slotPatch['selected_product_id']
            && $slotPatch['selected_size']
            && ($slotPatch['selected_variant_id'] || $slotPatch['selected_color_id'])
            && $slotPatch['intent_purchase']
        );

        if ($hasSelection) {
            $stage = $this->promoteStage($stage, self::STAGE_SELECTION);
        }

        if ($hasCheckoutPrerequisites) {
            $stage = $this->promoteStage($stage, self::STAGE_CHECKOUT_READY);
        }

        if ($slotPatch['delivery_complete']) {
            $stage = $this->promoteStage($stage, self::STAGE_CHECKOUT);
        }

        if ($modelStage === self::STAGE_SELECTION && $hasSelection) {
            $stage = $this->promoteStage($stage, self::STAGE_SELECTION);
        }

        if ($modelStage === self::STAGE_CHECKOUT_READY && $hasCheckoutPrerequisites) {
            $stage = $this->promoteStage($stage, self::STAGE_CHECKOUT_READY);
        }

        if ($modelStage === self::STAGE_CHECKOUT && $slotPatch['delivery_complete']) {
            $stage = $this->promoteStage($stage, self::STAGE_CHECKOUT);
        }

        return $stage;
    }

    private function promoteStage(string $current, string $target): string
    {
        $currentOrder = self::STAGE_ORDER[$current] ?? 1;
        $targetOrder = self::STAGE_ORDER[$target] ?? 1;

        return $targetOrder > $currentOrder ? $target : $current;
    }

    private function buildSafeReply(string $reply, string $stage, array $slotPatch): string
    {
        $cleanReply = trim((string) preg_replace('/\s+/u', ' ', strip_tags($reply)));
        $cleanReply = Str::limit($cleanReply, 1200, '...');

        if ($cleanReply === '') {
            return $this->fallbackReplyForStage($stage, $slotPatch);
        }

        if (
            in_array($stage, [self::STAGE_INTEREST, self::STAGE_SELECTION], true)
            && $this->containsDeliveryRequest($cleanReply)
        ) {
            return $this->fallbackReplyForStage($stage, $slotPatch);
        }

        return $cleanReply;
    }

    private function fallbackReplyForStage(string $stage, array $slotPatch): string
    {
        $missing = is_array($slotPatch['missing_slots_json'] ?? null)
            ? $slotPatch['missing_slots_json']
            : [];

        if ($stage === self::STAGE_INTEREST || $stage === self::STAGE_SELECTION) {
            if (($slotPatch['selected_size'] ?? null) === null || in_array('selected_size', $missing, true)) {
                return 'Підкажіть, будь ласка, ваш розмір. Тоді одразу скажу, які варіанти є в наявності саме для вас.';
            }

            if (
                ($slotPatch['selected_product_id'] ?? null) === null
                || in_array('selected_product', $missing, true)
            ) {
                return 'Напишіть, будь ласка, яку модель обираєте, і я підкажу актуальну наявність та кольори.';
            }

            return 'Є кілька варіантів на ваші параметри. Який колір вам більше підходить?';
        }

        if ($stage === self::STAGE_CHECKOUT_READY) {
            return 'Чудово, можемо оформити. Напишіть, будь ласка: імʼя та прізвище, номер телефону, місто і відділення або поштомат Нової пошти.';
        }

        return 'Дякую, передаю замовлення в обробку. Якщо буде потрібно, менеджер уточнить деталі.';
    }

    private function containsDeliveryRequest(string $text): bool
    {
        return preg_match(
            '/\b(ім[\'’]я|прізвище|телефон|номер телефону|місто|адрес|відділен|поштомат|нова пошта)\b/ui',
            mb_strtolower($text)
        ) === 1;
    }

    private function detectPurchaseIntent(string $text): bool
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return false;
        }

        $phrases = [
            'беру',
            'хочу замовити',
            'давайте оформ',
            'оформляємо',
            'оформимо',
            'підходить',
            'замовляю',
            'замовлення',
            'купую',
        ];

        foreach ($phrases as $phrase) {
            if (str_contains($text, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeSize(?string $value): ?string
    {
        $value = $this->cleanNullableString($value);
        if ($value === null) {
            return null;
        }

        if (preg_match('/^(\d{2,3})(?:[.,](\d))?$/', $value, $matches) === 1) {
            return $matches[2] ?? null
                ? $matches[1] . '.' . $matches[2]
                : $matches[1];
        }

        return Str::limit($value, 40, '');
    }

    private function extractSizeFromText(string $text): ?string
    {
        if (preg_match('/(?:розмір|size)?\s*[:#-]?\s*(\d{2,3})(?:[.,](\d))?/ui', $text, $matches) === 1) {
            return isset($matches[2]) && $matches[2] !== ''
                ? $matches[1] . '.' . $matches[2]
                : $matches[1];
        }

        return null;
    }

    private function resolveColorId(?string $candidateColor, string $text): ?int
    {
        $candidates = [];
        $normalizedCandidate = $this->cleanNullableString($candidateColor);
        if ($normalizedCandidate !== null) {
            $candidates[] = mb_strtolower($normalizedCandidate);
        }

        if ($text !== '') {
            $tokens = preg_split('/[\s,.;:!?()\/\\\\]+/u', mb_strtolower($text)) ?: [];
            foreach ($tokens as $token) {
                $token = trim($token);
                if ($token !== '') {
                    $candidates[] = $token;
                }
            }
        }

        $candidates = array_values(array_unique($candidates));
        if ($candidates === []) {
            return null;
        }

        foreach ($candidates as $candidate) {
            $colorId = Color::query()
                ->whereRaw('LOWER(name) = ?', [$candidate])
                ->value('id');

            if ($colorId) {
                return (int) $colorId;
            }
        }

        foreach ($candidates as $candidate) {
            $colorId = Color::query()
                ->whereRaw('LOWER(name) LIKE ?', [$candidate . '%'])
                ->value('id');

            if ($colorId) {
                return (int) $colorId;
            }
        }

        return null;
    }

    private function calculateMissingSlots(array $data): array
    {
        $missing = [];

        if (empty($data['selected_product_id'])) {
            $missing[] = 'selected_product';
        }

        if (empty($data['selected_size'])) {
            $missing[] = 'selected_size';
        }

        if (empty($data['selected_variant_id'])) {
            $missing[] = 'selected_variant';
        }

        if (empty($data['intent_purchase'])) {
            $missing[] = 'purchase_intent';
        }

        $delivery = is_array($data['delivery'] ?? null) ? $data['delivery'] : [];
        foreach (['name', 'phone', 'city', 'warehouse'] as $field) {
            if ($this->cleanNullableString($delivery[$field] ?? null) === null) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    private function isDeliveryComplete(array $delivery): bool
    {
        foreach (['name', 'phone', 'city', 'warehouse'] as $field) {
            if ($this->cleanNullableString($delivery[$field] ?? null) === null) {
                return false;
            }
        }

        return true;
    }

    private function updateConversationMeta(
        ChatConversation $conversation,
        string $agentCode,
        string $stage,
        int $stateId,
        int $runId
    ): void {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $meta['ai'] = [
            'enabled' => true,
            'agent_code' => $agentCode,
            'stage' => $stage,
            'state_id' => $stateId,
            'last_run_id' => $runId,
            'updated_at' => now()->toDateTimeString(),
        ];

        $conversation->meta = $meta;
        $conversation->save();
    }

    private function logEvent(
        int $conversationId,
        ?int $stateId,
        ?int $runId,
        string $eventType,
        ?string $fromStage,
        ?string $toStage,
        array $payload = []
    ): void {
        ChatAiEvent::query()->create([
            'conversation_id' => $conversationId,
            'state_id' => $stateId,
            'run_id' => $runId,
            'event_type' => $eventType,
            'from_stage' => $fromStage,
            'to_stage' => $toStage,
            'payload_json' => $payload !== [] ? $payload : null,
            'created_at' => now(),
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, ChatMessage>
     */
    private function loadHistory(int $conversationId): \Illuminate\Support\Collection
    {
        $limit = max(4, (int) config('services.chat_ai.max_messages', 12));

        return ChatMessage::query()
            ->where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->sortBy('id')
            ->values();
    }

    private function normalizeStage(mixed $value): ?string
    {
        $stage = $this->cleanNullableString(is_scalar($value) ? (string) $value : null);
        if ($stage === null) {
            return null;
        }

        return isset(self::STAGE_ORDER[$stage]) ? $stage : null;
    }

    private function normalizeIntent(mixed $value): ?string
    {
        $intent = $this->cleanNullableString(is_scalar($value) ? (string) $value : null);
        if ($intent === null) {
            return null;
        }

        return Str::limit($intent, 64, '');
    }

    private function cleanNullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $clean = trim((string) $value);

        return $clean !== '' ? $clean : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (!is_string($value)) {
            return false;
        }

        return in_array(mb_strtolower(trim($value)), ['1', 'true', 'yes', 'так'], true);
    }

    private function countInputChars(array $messages): int
    {
        $sum = 0;
        foreach ($messages as $message) {
            $sum += mb_strlen((string) ($message['content'] ?? ''));
        }

        return $sum;
    }

    private function hasRecentOperatorActivity(int $conversationId): bool
    {
        return ChatMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('direction', 'outbound')
            ->where('source', 'operator')
            ->where('created_at', '>=', now()->subMinutes(15))
            ->exists();
    }
}
