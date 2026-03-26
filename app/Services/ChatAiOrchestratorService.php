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
use App\Models\ProductMedia;
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
    private ?array $settingsCache = null;

    public function __construct(
        private readonly ChatService $chatService,
        private readonly MetaService $metaService,
        private readonly ChatAiSettingsService $chatAiSettingsService,
        private readonly ChatAiKnowledgeService $chatAiKnowledgeService
    ) {
    }

    public function handleBufferedInboundMessageById(int $messageId): void
    {
        $message = ChatMessage::query()
            ->with(['conversation.contact', 'conversation.customer'])
            ->find($messageId);

        if (!$message || $message->direction !== 'inbound' || $message->source !== 'webhook') {
            return;
        }

        $delaySeconds = $this->resolveReplyDelaySeconds();
        if ($delaySeconds > 0) {
            usleep($delaySeconds * 1_000_000);
        }

        $message = ChatMessage::query()
            ->with(['conversation.contact', 'conversation.customer'])
            ->find($messageId);

        if (!$message || $message->direction !== 'inbound' || $message->source !== 'webhook') {
            return;
        }

        if (!$this->isLatestInboundWebhookMessage($message->conversation_id, $message->id)) {
            return;
        }

        $conversation = $message->conversation;
        $contact = $conversation?->contact;
        $customer = $conversation?->customer;
        $platform = (string) ($contact?->platform ?? '');

        if (!$conversation || !$contact || !$customer || !in_array($platform, ['messenger', 'instagram'], true)) {
            return;
        }

        $this->handleInboundMessage(
            $conversation,
            $message,
            $customer,
            $contact,
            $platform
        );
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

        if (!$this->isConversationAiEnabled($conversation)) {
            return;
        }

        if (
            !$this->allowAssignedConversations()
            && $conversation->assigned_user_id !== null
        ) {
            return;
        }

        if (!$this->isLatestInboundWebhookMessage($conversation->id, $inboundMessage->id)) {
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
                'agent_code' => $this->settings()['default_agent_code'],
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
            $nextStage = $this->resolveNextStage($stageBefore, $normalized['stage'], $slotPatch, (string) ($normalized['action'] ?? 'text'));
            $reply = $this->buildSafeReply($normalized['reply'], $nextStage, $slotPatch);
            $mediaAttachment = $this->resolveAiMediaAttachment($inputText, $normalized, $state, $slotPatch);
            if ($mediaAttachment !== null) {
                if ($this->shouldSuppressTextForMediaAttachment($inputText, $normalized, $mediaAttachment)) {
                    $reply = '';
                } else {
                    $reply = $this->sanitizeReplyForMediaAttachment($reply, $mediaAttachment);
                }
            }

            if ($reply === '' && $mediaAttachment === null) {
                throw new \RuntimeException('Chat AI: порожня відповідь моделі після санітизації.');
            }

            $outboundMessage = null;
            if ($mediaAttachment !== null) {
                $attachmentMetaResult = $this->metaService->sendMessage(
                    $customer,
                    '',
                    [$mediaAttachment['meta_payload']],
                    $platform,
                    $contact->external_user_id
                );

                if (!$attachmentMetaResult) {
                    throw new \RuntimeException('Chat AI: Meta API повернув помилку при відправці медіа.');
                }

                $attachmentMessage = $this->chatService->storeMessage($conversation, [
                    'direction' => 'outbound',
                    'external_message_id' => $attachmentMetaResult['message_id'] ?? null,
                    'delivery_status' => 'sent',
                    'source' => 'system',
                    'text' => null,
                    'meta' => [
                        'ai' => [
                            'agent_id' => $agent->id,
                            'agent_code' => $agent->code,
                            'run_id' => $run->id,
                            'stage' => $nextStage,
                            'media_source' => $mediaAttachment['source'] ?? null,
                        ],
                    ],
                    'sent_at' => now(),
                ], [$mediaAttachment['stored_attachment']]);

                $this->chatService->updateConversationAfterMessage($conversation, $attachmentMessage, false);
                $outboundMessage = $attachmentMessage;
            }

            if ($reply !== '') {
                $metaResult = $this->metaService->sendMessage(
                    $customer,
                    $reply,
                    [],
                    $platform,
                    $contact->external_user_id
                );

                if (!$metaResult) {
                    throw new \RuntimeException('Chat AI: Meta API повернув помилку при відправці тексту.');
                }

                $textMessage = $this->chatService->storeMessage($conversation, [
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

                $this->chatService->updateConversationAfterMessage($conversation, $textMessage, false);
                $outboundMessage = $textMessage;
            }

            if (!$outboundMessage) {
                throw new \RuntimeException('Chat AI: не вдалося сформувати outbound-повідомлення.');
            }

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
        return (bool) $this->settings()['enabled'];
    }

    private function resolveAgent(): ?ChatAiAgent
    {
        $code = (string) $this->settings()['default_agent_code'];

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
        $knowledgeBlock = $this->chatAiKnowledgeService->buildKnowledgePromptBlock();

        $basePrompt = trim($promptVersion->system_prompt) . "\n\n"
            . "Працюй тільки українською мовою.\n"
            . "Відповідь повертай ТІЛЬКИ у JSON без markdown.\n"
            . "Схема JSON:\n"
            . "{\n"
            . "  \"action\": \"text|send_product_photo|send_collage|ask_clarifying|checkout_request|none\",\n"
            . "  \"reply\": \"string\",\n"
            . "  \"stage\": \"interest|selection|checkout_ready|checkout\",\n"
            . "  \"last_intent\": \"string|null\",\n"
            . "  \"intent_purchase\": true|false,\n"
            . "  \"requires_human\": true|false,\n"
            . "  \"model_phrase\": \"string|null\",\n"
            . "  \"selected_size\": \"string|null\",\n"
            . "  \"selected_color\": \"string|null\",\n"
            . "  \"selected_product_id\": number|null,\n"
            . "  \"selected_variant_id\": number|null,\n"
            . "  \"cart_items\": [\n"
            . "    {\n"
            . "      \"model\": \"string|null\",\n"
            . "      \"color\": \"string|null\",\n"
            . "      \"size\": \"string|null\",\n"
            . "      \"price\": number|null,\n"
            . "      \"qty\": number,\n"
            . "      \"line_total\": number|null,\n"
            . "      \"product_id\": number|null,\n"
            . "      \"variant_id\": number|null,\n"
            . "      \"color_id\": number|null\n"
            . "    }\n"
            . "  ],\n"
            . "  \"missing_slots\": [\"selected_product\",\"selected_size\",\"selected_variant\",\"name\",\"phone\",\"city\",\"warehouse\"],\n"
            . "  \"delivery_fields\": {\"name\": \"string|null\", \"phone\": \"string|null\", \"city\": \"string|null\", \"warehouse\": \"string|null\"}\n"
            . "}\n"
            . "Дозволено кілька позицій у одному замовленні. Якщо клієнт просить 2+ товари, додай їх у cart_items.\n"
            . "Заборонено змушувати клієнта обрати лише одну позицію, якщо він явно хоче кілька.\n"
            . "Не запитуй дані доставки (ПІБ, телефон, місто, відділення/поштомат), доки stage не дійшов до checkout_ready.\n"
            . "Ти сам визначаєш action. PHP-код не вирішує intent за клієнта, а лише виконує твою action-команду.\n"
            . "На етапах interest та selection ти працюєш як живий консультант, а не як скриптовий бот.\n"
            . "Якщо клієнт просить фото конкретного кольору або товару, повертай action=send_product_photo.\n"
            . "Якщо клієнт просить показати всі кольори, моделі, варіанти або асортимент, повертай action=send_collage.\n"
            . "Якщо потрібно лише відповісти текстом, повертай action=text.\n"
            . "Якщо потрібно м'яко уточнити модель або колір, повертай action=ask_clarifying.\n"
            . "На етапі interest не вимагай розмір, якщо клієнт просить фото, кольори, модель, усі варіанти або просто цікавиться товаром. У таких запитах спочатку покажи фото/варіанти/кольори і лише потім, за потреби, м'яко уточнюй колір або модель.\n"
            . "Не проси розмір, доки клієнт сам не питає про наявність конкретного розміру, підбір або не переходить до замовлення.\n"
            . "Не став жодних додаткових питань, якщо користувач просить просто показати фото, модель, кольори або асортимент.\n"
            . "Не вважай код з колажу (наприклад 20, 41, 42) розміром. Код колажу — це ідентифікатор позиції в межах моделі.\n"
            . "Заповнюй missing_slots тільки коли клієнт реально переходить до оформлення або вже хоче купити. На етапах interest та selection для звичайних консультацій missing_slots має бути [].\n"
            . "Не вставляй у reply сирі URL фото, відео або колажів. Якщо потрібне фото чи колаж, просто напиши коротко без посилання: наприклад, 'Надсилаю фото.' або 'Надсилаю колаж.' CRM відправить медіа окремо.\n"
            . "Якщо клієнт прямо просить показати або скинути фото/усі кольори/усі варіанти, не став додаткових питань і не додавай зайвий текст. У такому випадку reply має бути порожнім або максимально коротким, бо CRM сама відправить потрібне медіа.\n"
            . "Одне уточнююче питання за раз.\n"
            . "policy_json=" . $policyJson;

        if ($knowledgeBlock !== '') {
            $basePrompt .= "\n\n" . $knowledgeBlock;
        }

        return $basePrompt;
    }

    private function buildStateContext(
        ChatAiConversationState $state,
        ChatConversation $conversation,
        string $platform,
        ChatAiPromptVersion $promptVersion
    ): string {
        $shouldExposeMissingSlots = (bool) $state->intent_purchase
            || in_array($state->stage, [self::STAGE_CHECKOUT_READY, self::STAGE_CHECKOUT], true);

        $context = [
            'conversation_id' => $conversation->id,
            'platform' => $platform,
            'current_stage' => $state->stage,
            'intent_purchase' => (bool) $state->intent_purchase,
            'selected_product_id' => $state->selected_product_id,
            'selected_variant_id' => $state->selected_variant_id,
            'selected_color_id' => $state->selected_color_id,
            'selected_size' => $state->selected_size,
            'selected_model_phrase' => $state->slots_json['selected_model_phrase'] ?? null,
            'slots' => $state->slots_json ?? [],
            'current_cart' => is_array($state->slots_json['cart_items'] ?? null) ? $state->slots_json['cart_items'] : [],
            'missing_slots' => $shouldExposeMissingSlots ? ($state->missing_slots_json ?? []) : [],
            'policy_json' => $promptVersion->policy_json ?? [],
            'model_catalog' => $this->chatAiKnowledgeService->productCatalogContext(15, 12),
            'product_model_maps' => $this->chatAiKnowledgeService->productMapContext(30),
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

        $stage = $this->normalizeStage($payload['stage'] ?? null);
        $intentPurchase = $this->toBool($payload['intent_purchase'] ?? $payload['purchase_intent'] ?? false);
        $normalizedMissingSlots = array_values(array_unique(array_filter(array_map(
            fn ($slot) => $this->cleanNullableString($slot),
            $missingSlots
        ))));

        if (!$intentPurchase && !in_array($stage, [self::STAGE_CHECKOUT_READY, self::STAGE_CHECKOUT], true)) {
            $normalizedMissingSlots = [];
        }

        return [
            'action' => $this->normalizeAction($payload['action'] ?? null),
            'reply' => trim((string) ($payload['reply'] ?? $payload['response'] ?? '')),
            'stage' => $stage,
            'last_intent' => $this->normalizeIntent($payload['last_intent'] ?? null),
            'intent_purchase' => $intentPurchase,
            'requires_human' => $this->toBool($payload['requires_human'] ?? false),
            'model_phrase' => $this->cleanNullableString($payload['model_phrase'] ?? null),
            'selected_size' => $this->cleanNullableString($payload['selected_size'] ?? $payload['size'] ?? null),
            'selected_color' => $this->cleanNullableString($payload['selected_color'] ?? $payload['color'] ?? null),
            'selected_product_id' => $this->nullableInt($payload['selected_product_id'] ?? $payload['product_id'] ?? null),
            'selected_variant_id' => $this->nullableInt($payload['selected_variant_id'] ?? $payload['variant_id'] ?? null),
            'cart_items' => $this->normalizeCartItems($payload['cart_items'] ?? []),
            'missing_slots' => $normalizedMissingSlots,
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
        $cartItems = $this->normalizeCartItems($slots['cart_items'] ?? []);
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
        $selectedModelPhrase = $this->cleanNullableString($slots['selected_model_phrase'] ?? null);

        $candidateModelPhrase = $this->cleanNullableString($normalized['model_phrase'] ?? null);
        if ($candidateModelPhrase !== null) {
            $selectedModelPhrase = $candidateModelPhrase;
        }

        $mapped = $this->chatAiKnowledgeService->resolveMappedProduct($inputText, $selectedModelPhrase);
        if ($mapped) {
            if (!empty($mapped['model_phrase'])) {
                $selectedModelPhrase = (string) $mapped['model_phrase'];
            }

            if (!$selectedProductId && !empty($mapped['product_id'])) {
                $selectedProductId = (int) $mapped['product_id'];
            }

            if (!$selectedVariantId && !empty($mapped['variant_id'])) {
                $selectedVariantId = (int) $mapped['variant_id'];
            }

            if (!$selectedColorId && !empty($mapped['color_id'])) {
                $selectedColorId = (int) $mapped['color_id'];
            }

            if (!$selectedSize && !empty($mapped['size_hint'])) {
                $selectedSize = (string) $mapped['size_hint'];
            }
        }

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

        if ($selectedProductId && !$selectedColorId) {
            $productColorId = Product::query()
                ->whereKey($selectedProductId)
                ->value('color_id');

            if ($productColorId) {
                $selectedColorId = (int) $productColorId;
            }
        }

        if ($selectedProductId && $selectedSize && !$selectedVariantId) {
            $matchedVariant = $this->findVariantForProductSize($selectedProductId, $selectedSize);
            if ($matchedVariant) {
                $selectedVariantId = (int) $matchedVariant->id;
            }
        }

        foreach (($normalized['cart_items'] ?? []) as $incomingItem) {
            $cartItems = $this->upsertCartItem($cartItems, $incomingItem);
        }

        if ($cartItems === [] && !empty($normalized['intent_purchase'])) {
            $fallbackItem = $this->buildFallbackCartItem(
                $selectedProductId,
                $selectedVariantId,
                $selectedColorId,
                $normalized['selected_color'] ?? null,
                $selectedSize
            );

            if ($fallbackItem !== null) {
                $cartItems[] = $fallbackItem;
            }
        }

        if ($cartItems !== []) {
            $slots['cart_items'] = $cartItems;
        } else {
            unset($slots['cart_items']);
        }

        if ($selectedModelPhrase !== null) {
            $slots['selected_model_phrase'] = $selectedModelPhrase;
        } else {
            unset($slots['selected_model_phrase']);
        }

        $primaryItem = $cartItems[0] ?? null;
        if (is_array($primaryItem)) {
            $selectedProductId = $this->nullableInt($primaryItem['product_id'] ?? null) ?: $selectedProductId;
            $selectedVariantId = $this->nullableInt($primaryItem['variant_id'] ?? null) ?: $selectedVariantId;
            $selectedColorId = $this->nullableInt($primaryItem['color_id'] ?? null) ?: $selectedColorId;
            $selectedSize = $this->normalizeSize((string) ($primaryItem['size'] ?? '')) ?: $selectedSize;
        }

        $intentPurchase = (bool) ($state->intent_purchase || $normalized['intent_purchase']);

        $shouldTrackMissingSlots = !empty($normalized['intent_purchase'])
            || in_array((string) ($normalized['stage'] ?? $state->stage), [self::STAGE_CHECKOUT_READY, self::STAGE_CHECKOUT], true)
            || in_array($state->stage, [self::STAGE_CHECKOUT_READY, self::STAGE_CHECKOUT], true);

        $missingSlots = $shouldTrackMissingSlots ? $normalized['missing_slots'] : [];
        if ($shouldTrackMissingSlots && $missingSlots === []) {
            $missingSlots = $this->calculateMissingSlots([
                'selected_product_id' => $selectedProductId,
                'selected_variant_id' => $selectedVariantId,
                'selected_size' => $selectedSize,
                'intent_purchase' => $intentPurchase,
                'delivery' => $delivery,
                'cart_items' => $cartItems,
            ]);
        }

        $hasCartItems = $cartItems !== [];
        $hasReadyCartItem = collect($cartItems)->contains(function (array $item): bool {
            $hasModel = $this->cleanNullableString($item['model'] ?? null) !== null
                || $this->nullableInt($item['product_id'] ?? null) !== null
                || $this->nullableInt($item['variant_id'] ?? null) !== null;
            $hasColor = $this->cleanNullableString($item['color'] ?? null) !== null
                || $this->nullableInt($item['color_id'] ?? null) !== null;
            $hasSize = $this->normalizeSize((string) ($item['size'] ?? '')) !== null;

            return $hasModel && $hasColor && $hasSize;
        });

        return [
            'slots_json' => $slots,
            'missing_slots_json' => $missingSlots,
            'selected_model_phrase' => $selectedModelPhrase,
            'selected_product_id' => $selectedProductId,
            'selected_variant_id' => $selectedVariantId,
            'selected_color_id' => $selectedColorId,
            'selected_size' => $selectedSize,
            'intent_purchase' => $intentPurchase,
            'delivery_complete' => $this->isDeliveryComplete($delivery),
            'has_cart_items' => $hasCartItems,
            'has_ready_cart_item' => $hasReadyCartItem,
        ];
    }

    private function resolveNextStage(string $currentStage, ?string $modelStage, array $slotPatch, string $action = 'text'): string
    {
        if (
            in_array($action, ['send_product_photo', 'send_collage'], true)
            && empty($slotPatch['intent_purchase'])
            && $currentStage === self::STAGE_INTEREST
        ) {
            return self::STAGE_INTEREST;
        }

        $stage = isset(self::STAGE_ORDER[$modelStage ?? '']) ? $modelStage : $currentStage;
        if (!isset(self::STAGE_ORDER[$stage])) {
            $stage = self::STAGE_INTEREST;
        }

        if ($stage === self::STAGE_CHECKOUT && !$slotPatch['delivery_complete']) {
            return !empty($slotPatch['intent_purchase'])
                ? self::STAGE_CHECKOUT_READY
                : self::STAGE_SELECTION;
        }

        if ($stage === self::STAGE_CHECKOUT_READY && empty($slotPatch['intent_purchase'])) {
            return self::STAGE_SELECTION;
        }

        return $stage;
    }

    private function buildSafeReply(string $reply, string $stage, array $slotPatch): string
    {
        $cleanReply = trim((string) preg_replace('/\s+/u', ' ', strip_tags($reply)));
        $cleanReply = Str::limit($cleanReply, 1200, '...');

        if ($cleanReply === '') {
            return '';
        }

        if (
            in_array($stage, [self::STAGE_INTEREST, self::STAGE_SELECTION], true)
            && $this->containsDeliveryRequest($cleanReply)
        ) {
            return '';
        }

        return $cleanReply;
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $slotPatch
     * @return array<string, mixed>|null
     */
    private function resolveAiMediaAttachment(
        string $inputText,
        array $normalized,
        ChatAiConversationState $state,
        array $slotPatch
    ): ?array {
        $action = (string) ($normalized['action'] ?? 'text');
        if (!$this->actionRequiresMedia($action)) {
            return null;
        }

        $preferCollage = $this->actionPrefersCollage($action);
        $productId = $this->nullableInt($slotPatch['selected_product_id'] ?? null) ?: $state->selected_product_id;
        $variantId = $this->nullableInt($slotPatch['selected_variant_id'] ?? null) ?: $state->selected_variant_id;
        $colorId = $this->nullableInt($slotPatch['selected_color_id'] ?? null) ?: $state->selected_color_id;
        $modelPhrase = $this->cleanNullableString($normalized['model_phrase'] ?? null)
            ?: $this->cleanNullableString($slotPatch['selected_model_phrase'] ?? null)
            ?: $this->cleanNullableString($state->slots_json['selected_model_phrase'] ?? null);

        $mapped = null;
        if ($modelPhrase !== null) {
            $mapped = $this->chatAiKnowledgeService->resolveModelMapByPhrase($modelPhrase);
        }
        if ($mapped === null) {
            $mapped = $this->chatAiKnowledgeService->resolveMappedProduct($inputText, $modelPhrase);
        }
        if ($mapped === null) {
            $mapped = $this->chatAiKnowledgeService->resolveMappedProduct((string) ($normalized['reply'] ?? ''), $modelPhrase);
        }
        if (!$productId && !empty($mapped['product_id'])) {
            $productId = (int) $mapped['product_id'];
        }
        if (!$variantId && !empty($mapped['variant_id'])) {
            $variantId = (int) $mapped['variant_id'];
        }
        if (!$colorId && !empty($mapped['color_id'])) {
            $colorId = (int) $mapped['color_id'];
        }

        if ($productId && !$colorId) {
            $productColorId = Product::query()
                ->whereKey($productId)
                ->value('color_id');
            if ($productColorId) {
                $colorId = (int) $productColorId;
            }
        }

        if ($productId && !$preferCollage) {
            $resolvedMedia = $this->findPreferredProductMedia($productId, $variantId, $colorId);
            if ($resolvedMedia !== null) {
                return $resolvedMedia;
            }
        }

        if ($productId) {
            $product = Product::query()
                ->select(['id', 'main_photo_path', 'color_id'])
                ->find($productId);

            if ($product && !$preferCollage && $product->main_photo_url) {
                return $this->buildMediaAttachmentPayload(
                    $product->main_photo_url,
                    'image',
                    'main_photo',
                    ['product_id' => $productId, 'variant_id' => $variantId, 'color_id' => $colorId ?: $product->color_id]
                );
            }
        }

        $mappedCollageUrl = $this->cleanNullableString($mapped['collage_url'] ?? null);
        if ($mappedCollageUrl !== null) {
            return $this->buildMediaAttachmentPayload(
                $mappedCollageUrl,
                'image',
                'collage',
                ['product_id' => $productId, 'variant_id' => $variantId, 'color_id' => $colorId]
            );
        }

        $mapForProduct = $this->chatAiKnowledgeService->resolveModelMapForProduct($productId, $colorId);
        $fallbackCollageUrl = $this->cleanNullableString($mapForProduct['collage_url'] ?? null);
        if ($fallbackCollageUrl !== null) {
            return $this->buildMediaAttachmentPayload(
                $fallbackCollageUrl,
                'image',
                'collage',
                ['product_id' => $productId, 'variant_id' => $variantId, 'color_id' => $colorId]
            );
        }

        return null;
    }

    private function actionRequiresMedia(string $action): bool
    {
        return in_array($action, ['send_product_photo', 'send_collage'], true);
    }

    private function actionPrefersCollage(string $action): bool
    {
        return $action === 'send_collage';
    }

    private function normalizeAction(mixed $value): string
    {
        $action = $this->cleanNullableString(is_scalar($value) ? (string) $value : null);
        $allowed = ['text', 'send_product_photo', 'send_collage', 'ask_clarifying', 'checkout_request', 'none'];

        return $action !== null && in_array($action, $allowed, true)
            ? $action
            : 'text';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findPreferredProductMedia(int $productId, ?int $variantId, ?int $colorId): ?array
    {
        $baseQuery = ProductMedia::query()
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->where('media_type', 'image');

        if ($variantId) {
            $variantMedia = (clone $baseQuery)
                ->where('variant_id', $variantId)
                ->orderByDesc('is_primary')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();

            if ($variantMedia) {
                return $this->buildMediaAttachmentPayload(
                    (string) $variantMedia->url,
                    (string) $variantMedia->media_type,
                    'product_media_variant',
                    [
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'color_id' => $colorId,
                        'media_id' => $variantMedia->id,
                    ]
                );
            }
        }

        if ($colorId) {
            $colorMedia = (clone $baseQuery)
                ->whereNull('variant_id')
                ->where('color_id', $colorId)
                ->orderByDesc('is_primary')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();

            if ($colorMedia) {
                return $this->buildMediaAttachmentPayload(
                    (string) $colorMedia->url,
                    (string) $colorMedia->media_type,
                    'product_media_color',
                    [
                        'product_id' => $productId,
                        'variant_id' => null,
                        'color_id' => $colorId,
                        'media_id' => $colorMedia->id,
                    ]
                );
            }
        }

        $primaryMedia = (clone $baseQuery)
            ->whereNull('variant_id')
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if ($primaryMedia) {
            return $this->buildMediaAttachmentPayload(
                (string) $primaryMedia->url,
                (string) $primaryMedia->media_type,
                'product_media_primary',
                [
                    'product_id' => $productId,
                    'variant_id' => null,
                    'color_id' => $primaryMedia->color_id,
                    'media_id' => $primaryMedia->id,
                ]
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>|null
     */
    private function buildMediaAttachmentPayload(string $url, string $type, string $source, array $meta = []): ?array
    {
        $publicUrl = $this->normalizePublicMediaUrl($url);
        if ($publicUrl === null) {
            return null;
        }

        $attachmentType = $type === 'video' ? 'video' : 'image';

        return [
            'source' => $source,
            'meta_payload' => [
                'type' => $attachmentType,
                'url' => $publicUrl,
            ],
            'stored_attachment' => [
                'type' => $attachmentType,
                'url' => $publicUrl,
                'original_url' => $publicUrl,
                'meta' => $meta !== [] ? $meta : null,
            ],
        ];
    }

    private function normalizePublicMediaUrl(?string $url): ?string
    {
        $clean = $this->cleanNullableString($url);
        if ($clean === null) {
            return null;
        }

        if (str_starts_with($clean, 'http://') || str_starts_with($clean, 'https://')) {
            return $clean;
        }

        return url(ltrim($clean, '/'));
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    private function sanitizeReplyForMediaAttachment(string $reply, array $attachment): string
    {
        $cleanReply = preg_replace('/https?:\/\/\S+/u', '', $reply) ?? $reply;

        if (($attachment['source'] ?? null) !== 'collage') {
            $cleanReply = preg_replace('/\(?\s*номер\s+\d+\s+на\s+колажі\s*\)?/ui', '', $cleanReply) ?? $cleanReply;
        }

        $cleanReply = preg_replace('/\(\s*\)/u', '', $cleanReply) ?? $cleanReply;
        $cleanReply = preg_replace('/:\s*(?=[,.!?]|$)/u', '', $cleanReply) ?? $cleanReply;
        $cleanReply = preg_replace('/\s{2,}/u', ' ', $cleanReply) ?? $cleanReply;
        $cleanReply = preg_replace('/\s+([,.;:!?])/u', '$1', $cleanReply) ?? $cleanReply;

        return trim($cleanReply);
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $attachment
     */
    private function shouldSuppressTextForMediaAttachment(string $inputText, array $normalized, array $attachment): bool
    {
        return $this->actionRequiresMedia((string) ($normalized['action'] ?? 'text'))
            && in_array((string) ($attachment['source'] ?? ''), [
            'product_media_variant',
            'product_media_color',
            'product_media_primary',
            'main_photo',
            'collage',
        ], true);
    }

    private function containsDeliveryRequest(string $text): bool
    {
        return preg_match(
            '/\b(ім[\'’]я|прізвище|телефон|номер телефону|місто|адрес|відділен|поштомат|нова пошта)\b/ui',
            mb_strtolower($text)
        ) === 1;
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
        $patterns = [
            '/\b(?:розмір|size|р\.?)\s*[:#-]?\s*(\d{2,3}(?:[.,]\d)?(?:\s*\/\s*\d{2,3}(?:[.,]\d)?)?)/ui',
            '/\b(\d{2,3}(?:[.,]\d)?(?:\s*\/\s*\d{2,3}(?:[.,]\d)?)?)\s*(?:розмір|size|р\.?)\b/ui',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches) === 1) {
                $size = preg_replace('/\s*\/\s*/u', '/', (string) ($matches[1] ?? '')) ?? '';
                return $this->normalizeSize($size);
            }
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
        $intentPurchase = !empty($data['intent_purchase']);

        if (!$intentPurchase) {
            return [];
        }

        $cartItems = is_array($data['cart_items'] ?? null) ? $data['cart_items'] : [];
        if ($cartItems !== [] && $intentPurchase) {
            $hasReadyItem = false;
            foreach ($cartItems as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $hasModel = $this->cleanNullableString($item['model'] ?? null) !== null
                    || $this->nullableInt($item['product_id'] ?? null) !== null
                    || $this->nullableInt($item['variant_id'] ?? null) !== null;
                $hasColor = $this->cleanNullableString($item['color'] ?? null) !== null
                    || $this->nullableInt($item['color_id'] ?? null) !== null;
                $hasSize = $this->normalizeSize((string) ($item['size'] ?? '')) !== null;

                if ($hasModel && $hasColor && $hasSize) {
                    $hasReadyItem = true;
                    break;
                }
            }

            if (!$hasReadyItem) {
                $missing[] = 'selected_product';
                $missing[] = 'selected_size';
                $missing[] = 'selected_variant';
            }
        } elseif ($intentPurchase) {
            if (empty($data['selected_product_id'])) {
                $missing[] = 'selected_product';
            }

            if (empty($data['selected_size'])) {
                $missing[] = 'selected_size';
            }

            if (empty($data['selected_variant_id'])) {
                $missing[] = 'selected_variant';
            }
        }

        $delivery = is_array($data['delivery'] ?? null) ? $data['delivery'] : [];
        if ($intentPurchase) {
            foreach (['name', 'phone', 'city', 'warehouse'] as $field) {
                if ($this->cleanNullableString($delivery[$field] ?? null) === null) {
                    $missing[] = $field;
                }
            }
        }

        return $missing;
    }

    /**
     * @return array<int, array{
     *   model:?string,color:?string,size:?string,price:?float,qty:int,line_total:?float,
     *   product_id:?int,variant_id:?int,color_id:?int
     * }>
     */
    private function normalizeCartItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $normalizedItem = $this->normalizeCartItem($item);
            if ($normalizedItem === null) {
                continue;
            }

            $normalized = $this->upsertCartItem($normalized, $normalizedItem);
        }

        return array_values($normalized);
    }

    /**
     * @param  array<int, array<string,mixed>>  $cartItems
     * @param  array<string,mixed>  $item
     * @return array<int, array<string,mixed>>
     */
    private function upsertCartItem(array $cartItems, array $item): array
    {
        $normalizedItem = $this->normalizeCartItem($item);
        if ($normalizedItem === null) {
            return $cartItems;
        }

        foreach ($cartItems as $index => $existing) {
            if (!is_array($existing) || !$this->canEnrichExistingCartItem($existing, $normalizedItem)) {
                continue;
            }

            $cartItems[$index] = $this->mergeCartItems($existing, $normalizedItem);

            return array_values($cartItems);
        }

        $key = $this->buildCartItemKey($normalizedItem);
        if ($key === null) {
            $cartItems[] = $normalizedItem;

            return array_values($cartItems);
        }

        foreach ($cartItems as $index => $existing) {
            $existingKey = $this->buildCartItemKey(is_array($existing) ? $existing : []);
            if ($existingKey !== $key) {
                continue;
            }

            $existingQty = max(1, (int) ($existing['qty'] ?? 1));
            $incomingQty = max(1, (int) ($normalizedItem['qty'] ?? 1));
            $nextQty = max($existingQty, $incomingQty);
            $price = $normalizedItem['price'] ?? $existing['price'] ?? null;

            $cartItems[$index] = [
                'model' => $normalizedItem['model'] ?? $existing['model'] ?? null,
                'color' => $normalizedItem['color'] ?? $existing['color'] ?? null,
                'size' => $normalizedItem['size'] ?? $existing['size'] ?? null,
                'price' => $price !== null ? (float) $price : null,
                'qty' => $nextQty,
                'line_total' => $price !== null ? round(((float) $price) * $nextQty, 2) : null,
                'product_id' => $normalizedItem['product_id'] ?? $existing['product_id'] ?? null,
                'variant_id' => $normalizedItem['variant_id'] ?? $existing['variant_id'] ?? null,
                'color_id' => $normalizedItem['color_id'] ?? $existing['color_id'] ?? null,
            ];

            return array_values($cartItems);
        }

        $cartItems[] = $normalizedItem;

        return array_values($cartItems);
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>|null
     */
    private function normalizeCartItem(array $item): ?array
    {
        $productId = $this->nullableInt($item['product_id'] ?? $item['selected_product_id'] ?? null);
        $variantId = $this->nullableInt($item['variant_id'] ?? $item['selected_variant_id'] ?? null);
        $colorId = $this->nullableInt($item['color_id'] ?? $item['selected_color_id'] ?? null);

        $model = $this->cleanNullableString($item['model'] ?? $item['product'] ?? null);
        $color = $this->cleanNullableString($item['color'] ?? null);
        $size = $this->normalizeSize($this->cleanNullableString($item['size'] ?? $item['selected_size'] ?? null) ?? '');

        $price = $this->nullableFloat($item['price'] ?? null);
        $qty = max(1, (int) ($item['qty'] ?? $item['quantity'] ?? 1));

        if ($variantId) {
            $variant = ProductVariant::query()
                ->select(['id', 'product_id', 'size'])
                ->find($variantId);
            if ($variant) {
                $variantId = $variant->id;
                $productId = $variant->product_id ?: $productId;
                if ($size === null && $variant->size) {
                    $size = $this->normalizeSize((string) $variant->size);
                }
            } else {
                $variantId = null;
            }
        }

        if ($productId && $variantId === null && $size !== null) {
            $matchedVariant = $this->findVariantForProductSize($productId, $size);
            if ($matchedVariant) {
                $variantId = (int) $matchedVariant->id;
            }
        }

        if ($productId) {
            $product = Product::query()
                ->select(['id', 'title', 'sale_price', 'color_id'])
                ->find($productId);
            if ($product) {
                if ($model === null) {
                    $model = $this->cleanNullableString((string) $product->title);
                }
                if ($price === null && $product->sale_price !== null) {
                    $price = (float) $product->sale_price;
                }
                if ($colorId === null && $product->color_id) {
                    $colorId = (int) $product->color_id;
                }
            } else {
                $productId = null;
            }
        }

        if ($colorId) {
            $colorModel = Color::query()
                ->select(['id', 'name'])
                ->find($colorId);
            if ($colorModel) {
                $colorId = (int) $colorModel->id;
                if ($color === null) {
                    $color = $this->cleanNullableString((string) $colorModel->name);
                }
            } else {
                $colorId = null;
            }
        }

        if ($colorId === null && $color !== null) {
            $resolvedColor = $this->resolveColorId($color, $color);
            if ($resolvedColor !== null) {
                $colorId = $resolvedColor;
            }
        }

        // У кошик пускаємо тільки осмислені позиції, щоб не накопичувати "сміття" (лише колір/лише одне поле).
        $hasModelSignal = $model !== null || $productId !== null || $variantId !== null;
        $hasColorSignal = $color !== null || $colorId !== null;
        $hasSizeSignal = $size !== null;
        $isMeaningfulItem = ($hasModelSignal && ($hasColorSignal || $hasSizeSignal))
            || ($hasColorSignal && $hasSizeSignal);

        if (!$isMeaningfulItem) {
            return null;
        }

        $lineTotal = $this->nullableFloat($item['line_total'] ?? null);
        if ($lineTotal === null && $price !== null) {
            $lineTotal = round($price * $qty, 2);
        }

        return [
            'model' => $model,
            'color' => $color,
            'size' => $size,
            'price' => $price,
            'qty' => $qty,
            'line_total' => $lineTotal,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'color_id' => $colorId,
        ];
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function buildCartItemKey(array $item): ?string
    {
        $variantId = $this->nullableInt($item['variant_id'] ?? null);
        if ($variantId !== null) {
            return 'v:' . $variantId;
        }

        $productId = $this->nullableInt($item['product_id'] ?? null);
        $size = $this->normalizeSize((string) ($item['size'] ?? ''));
        $colorId = $this->nullableInt($item['color_id'] ?? null);
        $color = mb_strtolower(trim((string) ($item['color'] ?? '')));
        $model = mb_strtolower(trim((string) ($item['model'] ?? '')));

        if ($productId !== null && $size !== null && ($colorId !== null || $color !== '')) {
            return implode('|', [
                'p:' . $productId,
                's:' . $size,
                'c:' . ($colorId !== null ? $colorId : $color),
            ]);
        }

        if ($model !== '' && $size !== null && $color !== '') {
            return implode('|', [
                'm:' . $model,
                's:' . $size,
                'c:' . $color,
            ]);
        }

        // Fallback: коли модель ще не визначена, але є колір+розмір.
        if ($size !== null && ($colorId !== null || $color !== '')) {
            return implode('|', [
                's:' . $size,
                'c:' . ($colorId !== null ? $colorId : $color),
            ]);
        }

        if ($model !== '' && ($colorId !== null || $color !== '')) {
            return implode('|', [
                'm:' . $model,
                'c:' . ($colorId !== null ? $colorId : $color),
            ]);
        }

        return null;
    }

    /**
     * Якщо є "чернетка" позиції без розміру/варіанта, а новий item її уточнює,
     * зливаємо це в одну позицію замість дубля.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     */
    private function canEnrichExistingCartItem(array $existing, array $incoming): bool
    {
        $existingVariantId = $this->nullableInt($existing['variant_id'] ?? null);
        $incomingVariantId = $this->nullableInt($incoming['variant_id'] ?? null);
        $existingSize = $this->normalizeSize((string) ($existing['size'] ?? ''));
        $incomingSize = $this->normalizeSize((string) ($incoming['size'] ?? ''));

        if ($incomingVariantId === null && $incomingSize === null) {
            return false;
        }

        if ($existingVariantId !== null || $existingSize !== null) {
            return false;
        }

        $existingProductId = $this->nullableInt($existing['product_id'] ?? null);
        $incomingProductId = $this->nullableInt($incoming['product_id'] ?? null);
        if ($existingProductId !== null && $incomingProductId !== null && $existingProductId !== $incomingProductId) {
            return false;
        }

        $existingColorId = $this->nullableInt($existing['color_id'] ?? null);
        $incomingColorId = $this->nullableInt($incoming['color_id'] ?? null);
        if ($existingColorId !== null && $incomingColorId !== null && $existingColorId !== $incomingColorId) {
            return false;
        }

        $existingColor = mb_strtolower(trim((string) ($existing['color'] ?? '')));
        $incomingColor = mb_strtolower(trim((string) ($incoming['color'] ?? '')));
        if ($existingColor !== '' && $incomingColor !== '' && $existingColor !== $incomingColor) {
            return false;
        }

        $existingModel = mb_strtolower(trim((string) ($existing['model'] ?? '')));
        $incomingModel = mb_strtolower(trim((string) ($incoming['model'] ?? '')));
        if ($existingProductId === null && $incomingProductId === null && $existingModel !== '' && $incomingModel !== '' && $existingModel !== $incomingModel) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeCartItems(array $existing, array $incoming): array
    {
        $existingQty = max(1, (int) ($existing['qty'] ?? 1));
        $incomingQty = max(1, (int) ($incoming['qty'] ?? 1));
        $nextQty = max($existingQty, $incomingQty);
        $price = $incoming['price'] ?? $existing['price'] ?? null;

        return [
            'model' => $incoming['model'] ?? $existing['model'] ?? null,
            'color' => $incoming['color'] ?? $existing['color'] ?? null,
            'size' => $incoming['size'] ?? $existing['size'] ?? null,
            'price' => $price !== null ? (float) $price : null,
            'qty' => $nextQty,
            'line_total' => $price !== null ? round(((float) $price) * $nextQty, 2) : null,
            'product_id' => $incoming['product_id'] ?? $existing['product_id'] ?? null,
            'variant_id' => $incoming['variant_id'] ?? $existing['variant_id'] ?? null,
            'color_id' => $incoming['color_id'] ?? $existing['color_id'] ?? null,
        ];
    }

    private function buildFallbackCartItem(
        ?int $selectedProductId,
        ?int $selectedVariantId,
        ?int $selectedColorId,
        ?string $selectedColorName,
        ?string $selectedSize
    ): ?array {
        $candidate = [
            'product_id' => $selectedProductId,
            'variant_id' => $selectedVariantId,
            'color_id' => $selectedColorId,
            'color' => $selectedColorName,
            'size' => $selectedSize,
            'qty' => 1,
        ];

        return $this->normalizeCartItem($candidate);
    }

    private function findVariantForProductSize(int $productId, string $selectedSize): ?ProductVariant
    {
        $variants = ProductVariant::query()
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'product_id', 'size']);

        foreach ($variants as $variant) {
            if ($this->variantMatchesSelectedSize((string) $variant->size, $selectedSize)) {
                return $variant;
            }
        }

        return null;
    }

    private function variantMatchesSelectedSize(string $variantSize, string $selectedSize): bool
    {
        $normalizedSelected = $this->normalizeSize($selectedSize);
        if ($normalizedSelected === null) {
            return false;
        }

        $normalizedVariant = $this->normalizeSize($variantSize);
        if ($normalizedVariant === $normalizedSelected) {
            return true;
        }

        $tokens = preg_split('/[^\d.]+/u', $variantSize) ?: [];
        foreach ($tokens as $token) {
            $normalizedToken = $this->normalizeSize($token);
            if ($normalizedToken !== null && $normalizedToken === $normalizedSelected) {
                return true;
            }
        }

        return false;
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
        $existingAiMeta = is_array($meta['ai'] ?? null) ? $meta['ai'] : [];
        $meta['ai'] = [
            'enabled' => array_key_exists('enabled', $existingAiMeta)
                ? (bool) $existingAiMeta['enabled']
                : true,
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
        $limit = max(4, (int) $this->settings()['max_messages']);

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

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = str_replace(',', '.', trim($value));
        if (!is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
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

    private function isConversationAiEnabled(ChatConversation $conversation): bool
    {
        $enabled = data_get($conversation->meta, 'ai.enabled');

        return $enabled === null ? true : (bool) $enabled;
    }

    private function allowAssignedConversations(): bool
    {
        return (bool) $this->settings()['allow_assigned_conversations'];
    }

    private function resolveReplyDelaySeconds(): int
    {
        return (int) $this->settings()['reply_delay_seconds'];
    }

    private function isLatestInboundWebhookMessage(int $conversationId, int $messageId): bool
    {
        $latestInboundId = ChatMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('direction', 'inbound')
            ->where('source', 'webhook')
            ->latest('id')
            ->value('id');

        return (int) $latestInboundId === $messageId;
    }

    /**
     * @return array{
     *   enabled: bool,
     *   default_agent_code: string,
     *   reply_delay_seconds: int,
     *   allow_assigned_conversations: bool,
     *   max_messages: int
     * }
     */
    private function settings(): array
    {
        if ($this->settingsCache === null) {
            $this->settingsCache = $this->chatAiSettingsService->get();
        }

        return $this->settingsCache;
    }
}
