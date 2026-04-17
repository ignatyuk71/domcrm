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

        $delaySeconds = $this->resolveDebounceSeconds();
        if ($delaySeconds > 0) {
            usleep($delaySeconds * 1_000_000);
        }

        $message = ChatMessage::query()
            ->with(['conversation.contact', 'conversation.customer'])
            ->find($messageId);

        if (!$message || $message->direction !== 'inbound' || $message->source !== 'webhook') {
            return;
        }

        // Debounce на рівні діалогу: обробляємо лише останнє вхідне повідомлення.
        if (!$this->isLatestInboundMessage($message->conversation_id, $message->id, ['webhook'])) {
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

    public function handleRecoveredInboundMessageById(int $messageId): void
    {
        $message = ChatMessage::query()
            ->with(['conversation.contact', 'conversation.customer'])
            ->find($messageId);

        if (!$message || $message->direction !== 'inbound' || $message->source !== 'sync') {
            return;
        }

        $sentAt = $message->sent_at ?? $message->created_at;
        if (!$sentAt || $sentAt->lt(now()->subMinutes(20))) {
            return;
        }

        if (!$this->isLatestInboundMessage($message->conversation_id, $message->id, ['webhook', 'sync'])) {
            return;
        }

        if ($this->hasOutboundAfterMessage($message)) {
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
            $platform,
            ['webhook', 'sync']
        );
    }

    public function handleInboundMessage(
        ChatConversation $conversation,
        ChatMessage $inboundMessage,
        Customer $customer,
        ChatContact $contact,
        string $platform,
        array $latestInboundSources = ['webhook']
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

        if (!$this->isLatestInboundMessage($conversation->id, $inboundMessage->id, $latestInboundSources)) {
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
            $platform,
            $inputText
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
            'model' => $this->resolveAgentModel($agent),
            'input_messages' => count($messages),
            'input_chars' => $inputChars,
            'started_at' => $startedAt,
        ]);

        $rawOutput = '';

        try {
            [$rawOutput, $usage] = $this->callOpenAi($messages, $agent);
            [$rawOutput, $usage, $normalized, $slotPatch, $nextStage, $reply, $mediaAttachments] = $this->prepareStructuredResponse(
                $agent,
                $messages,
                $rawOutput,
                $usage,
                $state,
                $stageBefore,
                $inputText
            );

            // Якщо поки AI думав прийшло нове inbound, старий run вже не має нічого відправляти.
            if ($this->shouldSkipStaleReply($conversation->id, $inboundMessage->id, $latestInboundSources)) {
                $latencyMs = (int) round((microtime(true) - $startedAtTs) * 1000);
                $run->update([
                    'status' => 'skipped',
                    'output_text' => $rawOutput,
                    'output_chars' => mb_strlen($reply),
                    'prompt_tokens' => $usage['prompt_tokens'],
                    'completion_tokens' => $usage['completion_tokens'],
                    'total_tokens' => $usage['total_tokens'],
                    'latency_ms' => $latencyMs,
                    'meta_json' => [
                        'stage_before' => $stageBefore,
                        'stage_after' => $stageBefore,
                        'missing_slots' => $slotPatch['missing_slots_json'],
                        'skipped_reason' => 'stale_run_newer_inbound_exists',
                    ],
                    'finished_at' => now(),
                ]);

                $this->logEvent($conversation->id, $state->id, $run->id, 'reply_skipped', $stageBefore, $stageBefore, [
                    'reason' => 'stale_run_newer_inbound_exists',
                    'message_id' => $inboundMessage->id,
                ]);

                return;
            }

            $reply = $this->prependGreetingIfNeeded($reply, $conversation->id);

            if ($reply === '' && $mediaAttachments === []) {
                throw new \RuntimeException('Chat AI: порожня відповідь моделі після санітизації.');
            }

            $outboundMessage = null;
            foreach ($mediaAttachments as $mediaAttachment) {
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
                'output_text' => $rawOutput !== '' ? $rawOutput : null,
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
        string $platform,
        string $inputText
    ): array {
        $messages = [];

        $messages[] = [
            'role' => 'system',
            'content' => $this->buildSystemPrompt($promptVersion),
        ];

        $messages[] = [
            'role' => 'system',
            'content' => $this->buildStateContext($state, $conversation, $platform, $promptVersion, $inputText),
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
        $schemaJson = json_encode(
            $this->structuredOutputJsonSchema(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        ) ?: '{}';

        $basePrompt = trim($promptVersion->system_prompt) . "\n\n"
            . "Працюй тільки українською мовою.\n"
            . "Відповідь повертай тільки у JSON без markdown.\n"
            . "PHP-код не визначає намір клієнта, а лише виконує твою action-команду.\n\n"
            . "JSON Schema:\n"
            . $schemaJson . "\n\n"
            . "Доступні action:\n"
            . "- text: звичайна текстова відповідь.\n"
            . "- send_product_photo: показати одне конкретне фото товару або кольору.\n"
            . "- send_product_gallery: показати кілька конкретних фото товарів або кольорів.\n"
            . "- send_collage: показати загальний каталог моделей або загальні колажі моделей без прив'язки до одного конкретного кольору чи товару.\n"
            . "- ask_clarifying: поставити одне коротке уточнення, якщо без нього неможливо відповісти точно.\n"
            . "- checkout_request: попросити дані для оформлення замовлення тільки після завершеного підбору і явного підтвердження клієнта, що можна оформляти.\n"
            . "- none: тільки для технічних або дубльованих повідомлень.\n\n"
            . "Правила форматування reply:\n"
            . "1. Не зливай усю відповідь в один абзац. Кожен смисловий блок пиши з нового рядка.\n"
            . "2. Між окремими блоками став один порожній рядок.\n"
            . "3. Якщо перелічуєш моделі, ціни, розміри, кольори або поля оформлення, кожен короткий елемент або кожен короткий блок пиши з нового рядка.\n"
            . "4. Для action=text і action=ask_clarifying, якщо у відповіді більше однієї думки, використовуй 2-6 коротких рядків замість одного довгого речення.\n"
            . "5. Для action=send_product_photo, action=send_product_gallery і action=send_collage reply може бути коротким, але дозволено 1-4 короткі рядки. Не зливай кілька окремих думок в один довгий рядок.\n"
            . "6. Для action=checkout_request кожне поле доставки пиши з нового рядка.\n"
            . "7. Для відповідей про ціну, наявність і розмірну сітку використовуй блочний формат: назва/ціна окремим рядком, далі порожній рядок, далі розміри або кольори окремими рядками, далі порожній рядок і одне питання в кінці.\n\n"
            . "Пріоритети прийняття рішення:\n"
            . "1. Якщо клієнт питає про ціну, вартість або скільки коштує товар, пріоритет має action=text. Навіть якщо в повідомленні є колір, модель або код товару, спочатку відповідай ціною. Фото можна запропонувати додатково, але не замість ціни.\n"
            . "2. Якщо клієнт питає про наявність, розміри, розмірну сітку, матеріал, підошву, догляд, доставку або оплату, пріоритет має action=text.\n"
            . "3. Якщо клієнт уже уточнив конкретний тип, модель або колір товару, це більше не загальний запит по асортименту. Після такого уточнення не використовуй action=send_collage.\n"
            . "4. Якщо клієнт просить показати одне конкретне фото одного кольору або одного товару, використовуй action=send_product_photo.\n"
            . "5. Якщо клієнт просить показати кілька конкретних варіантів уже уточненого запиту, використовуй action=send_product_gallery і заповнюй gallery_items тільки реально потрібними позиціями.\n"
            . "5a. Якщо клієнт явно назвав конкретний колір, навіть без уточнення моделі, це вже звужений запит. У такому випадку не використовуй action=send_collage. Використовуй action=send_product_gallery для всіх доступних релевантних позицій цього кольору.\n"
            . "6. Якщо клієнт просить показати всі моделі, всі кольори, усі варіанти або весь асортимент без уточнених параметрів, використовуй action=send_collage.\n"
            . "7. Якщо модель ще не визначена і запит загальний, наприклад клієнт пише 'ціна', 'які є', 'хочу замовити', 'розміри', 'покажіть', 'які кольори', send_collage означає каталог моделей. У такому випадку коротко покажи асортимент моделей і допоможи клієнту вибрати модель, колір або код з колажу.\n"
            . "8. Короткі follow-up повідомлення на кшталт 'можна фото', 'покажіть', 'скиньте', 'а які саме' потрібно трактувати в межах останнього уточненого вибору клієнта. Якщо в попередньому повідомленні вже вказані тип, модель або колір, не повертайся до action=send_collage.\n"
            . "9. Якщо клієнт питає про колір, якого немає у поточній моделі, поверни action=text, коротко скажи, що такого кольору немає, і переліч доступні кольори цієї моделі.\n"
            . "10. Якщо клієнт після показу моделей пише короткі фрази на зразок 'це всі?', 'є ще?', 'і все?', трактуй це як уточнення про асортимент, а не як завершення діалогу.\n"
            . "11. Якщо клієнт пише 'хочу замовити', 'беру' або схожий намір купити, це ще не означає перехід до checkout_request. Спочатку потрібно завершити підбір усіх позицій, кольорів і розмірів.\n"
            . "12. Переходь до checkout_request тільки якщо всі потрібні позиції вже визначені, для кожної позиції відомі модель, колір і розмір, а клієнт явно підтвердив, що більше нічого не додає і можна оформляти замовлення.\n"
            . "13. Якщо клієнт просить пораду за сценарієм використання, наприклад 'м'які і щоб можна було на вулицю', це правило має вищий пріоритет за send_collage: використовуй action=text, коротко порадь найкращу модель і не повторюй send_collage, якщо асортимент уже був показаний.\n"
            . "14. Якщо клієнт прямо просить показати конкретну модель, колір або розмір, наприклад 'покажіть вуличні чорні', 'скинь фото домашніх чорних на 39', пріоритет має action=send_product_photo. Не замінюй такий запит простою текстовою відповіддю.\n"
            . "15. Якщо після попереднього уточнення клієнт дає новий явний запит на іншу модель, інший колір або інше фото, наприклад 'і ще покажіть домашні чорні на 39', трактуй це як новий show-request. Не додавай у кошик попередній товар автоматично і не повертайся до старого незавершеного підтвердження.\n"
            . "16. Якщо модель, колір і розмір уже зрозумілі або їх можна однозначно вивести з поточного контексту, а клієнт пише 'додавайте', 'беру', 'ще одну пару', це означає підтвердження позиції. Не надсилай фото повторно і не став те саме питання вдруге: додай позицію в cart_items або уточни тільки відсутнє поле.\n"
            . "17. Якщо current_cart уже містить готові позиції і клієнт явно пише 'все', 'більше нічого не треба', 'оформляємо', пріоритет має action=checkout_request, а не повторне уточнення.\n"
            . "18. Для action=send_collage не заповнюй gallery_items конкретними товарами. Якщо тобі потрібно показати конкретні товари або конкретні кольори, використовуй action=send_product_gallery.\n\n"
            . "Правила поведінки:\n"
            . "1. Дозволено кілька позицій у одному замовленні. Якщо клієнт просить 2 або більше товари, додай їх у cart_items.\n"
            . "2. Заборонено змушувати клієнта обрати лише одну позицію, якщо він явно хоче кілька.\n"
            . "3. На етапах interest та selection ти працюєш як живий консультант, а не як скриптовий бот.\n"
            . "4. Якщо йдеться про асортимент магазину, використовуй у reply слова 'тапки' або 'тапочки', а не узагальнення 'взуття'.\n"
            . "5. Якщо клієнт прямо просить показати або скинути фото, кольори, модель, усі варіанти або асортимент, не став додаткових питань і не додавай зайвий текст.\n"
            . "6. Якщо потрібно м'яко уточнити модель або колір, використовуй action=ask_clarifying.\n"
            . "7. Якщо клієнт уже вибрав одну або кілька позицій, але ще не завершив підбір, не поспішай з оформленням. Спочатку допоможи дозібрати всі товари.\n"
            . "8. Перед checkout_request, якщо позиції вже зібрані, не повторюй довгий опис уже вибраного товару без потреби. Пиши коротко і природно: 'Бажаєте ще щось додати чи вже оформляємо замовлення?'.\n"
            . "9. Якщо клієнт уже надав частину або всі дані доставки, але потім просить дозамовити ще одну пару, повернися до selection, збережи delivery_fields і допоможи додати нову позицію.\n"
            . "10. Одне уточнююче питання за раз.\n"
            . "11. На короткі питання про характеристики (матеріал, мех/хутро, підошва, якість) відповідай одразу по суті і не повторюй повну комбінацію 'модель, колір, розмір' у кожній репліці. Повну комбінацію згадуй тільки коли уточнюєш або підтверджуєш вибір.\n"
            . "12. Якщо в попередньому ході вже був send_collage або send_product_photo, не повторюй той самий медіа-формат без нової користі. Наступна відповідь має рухати діалог вперед: порада, точніше фото, додавання в кошик або оформлення.\n"
            . "13. Якщо клієнт явно підтвердив дію словами 'добре, додавайте', 'так, додавайте', 'беру', 'все, оформляємо', не став повторне питання з тим самим змістом.\n\n"
            . "Приклади пріоритетних рішень:\n"
            . "- 'Мені треба щоб були м'які і щоб можна було вийти на вулицю. Що краще порадите?' -> action=text, коротка порада, без повторного send_collage.\n"
            . "- 'Тоді покажіть [тип] [конкретний колір]' -> action=send_product_photo.\n"
            . "- 'Які є [конкретний колір]?' -> action=send_product_gallery, тільки для варіантів цього кольору.\n"
            . "- 'Покажіть [конкретний колір]' -> action=send_product_gallery, тільки для варіантів цього кольору.\n"
            . "- 'Можна фото?' після попереднього уточнення кольору або моделі -> action=send_product_photo або action=send_product_gallery по останньому уточненому запиту, але не send_collage.\n"
            . "- 'І ще покажіть [тип] [конкретний колір] на [розмір]' -> action=send_product_photo для уточненої моделі; не додавай попередній товар у кошик автоматично.\n"
            . "- 'Добре, додавайте одну пару [конкретного кольору]' за вже зрозумілих моделі, кольору та розміру -> додай позицію в cart_items і коротко підтвердь, без повторного фото.\n"
            . "- 'Все, більше нічого не треба, оформляємо' за вже готового current_cart -> action=checkout_request.\n\n"
            . "Обмеження:\n"
            . "1. Не запитуй дані доставки (ПІБ, телефон, місто, відділення або поштомат), доки stage не дійшов до checkout_ready.\n"
            . "2. Не проси розмір, якщо клієнт просто хоче подивитися фото, кольори, модель, усі варіанти або асортимент.\n"
            . "3. Не вважай код з колажу розміром. Код колажу — це ідентифікатор позиції в межах моделі.\n"
            . "4. Якщо у current_input_map є item_code, трактуй поточне повідомлення як вибір позиції по коду колажу. У такому випадку не інтерпретуй це число як розмір або сантиметри.\n"
            . "5. Заповнюй missing_slots і переходь до checkout_request тільки тоді, коли підбір уже завершений: усі потрібні позиції визначені, для кожної позиції відомі модель, колір і розмір, а клієнт явно підтвердив, що більше нічого не додає і можна оформляти замовлення. Сам намір купити не є достатньою підставою для переходу до оформлення. На етапах interest та selection для звичайної консультації missing_slots має бути порожнім масивом.\n"
            . "6. Для всіх action, окрім action=none, поле reply обов'язково має бути непорожнім. Для медіа-дій (send_product_photo, send_product_gallery, send_collage) reply має бути коротким супровідним текстом у 1-4 коротких рядках, а не суцільним абзацом.\n"
            . "7. action=none дозволений тільки для технічних або дубльованих повідомлень. На нормальне повідомлення клієнта не повертай action=none з порожнім reply.\n"
            . "8. Не вставляй у reply сирі URL фото, відео або колажів. Якщо потрібне фото чи колаж, просто напиши коротко без посилання.\n"
            . "9. Не повертай медіа-дію, якщо в тебе немає конкретної моделі, товару або доступного attachment для виконання. У такому випадку відповідай текстом і коротко уточнюй модель або запит клієнта.\n"
            . "10. Для action=send_product_gallery не надсилай колаж, якщо клієнт просить лише кілька конкретних кольорів. У gallery_items повинні бути тільки реально доступні позиції.\n"
            . "11. У reply заборонено показувати внутрішні ідентифікатори CRM: product_id, variant_id, color_id, media_id та будь-які службові ID.\n"
            . "12. Для action=checkout_request: якщо бракує хоча б одного поля доставки (ПІБ, телефон, місто, відділення/поштомат), не пиши підтвердження \"замовлення прийнято\". Спочатку збери всі відсутні поля.\n\n"
            . "policy_json=" . $policyJson;

        if ($knowledgeBlock !== '') {
            $basePrompt .= "\n\n" . $knowledgeBlock;
        }

        return $basePrompt;
    }

    /**
     * @return array<string, mixed>
     */
    private function structuredOutputResponseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'chat_ai_reply',
                'strict' => true,
                'schema' => $this->structuredOutputJsonSchema(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function structuredOutputJsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => [
                        'text',
                        'send_product_photo',
                        'send_product_gallery',
                        'send_collage',
                        'ask_clarifying',
                        'checkout_request',
                        'none',
                    ],
                ],
                'reply' => ['type' => 'string'],
                'stage' => [
                    'type' => 'string',
                    'enum' => [
                        self::STAGE_INTEREST,
                        self::STAGE_SELECTION,
                        self::STAGE_CHECKOUT_READY,
                        self::STAGE_CHECKOUT,
                    ],
                ],
                'last_intent' => [
                    'type' => ['string', 'null'],
                ],
                'intent_purchase' => ['type' => 'boolean'],
                'requires_human' => ['type' => 'boolean'],
                'model_phrase' => ['type' => ['string', 'null']],
                'selected_size' => ['type' => ['string', 'null']],
                'selected_color' => ['type' => ['string', 'null']],
                'selected_product_id' => ['type' => ['integer', 'null']],
                'selected_variant_id' => ['type' => ['integer', 'null']],
                'gallery_items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'product_id' => ['type' => ['integer', 'null']],
                            'variant_id' => ['type' => ['integer', 'null']],
                            'color_id' => ['type' => ['integer', 'null']],
                            'color' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['product_id', 'variant_id', 'color_id', 'color'],
                        'additionalProperties' => false,
                    ],
                ],
                'cart_items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'model' => ['type' => ['string', 'null']],
                            'color' => ['type' => ['string', 'null']],
                            'size' => ['type' => ['string', 'null']],
                            'price' => ['type' => ['number', 'null']],
                            'qty' => ['type' => 'integer'],
                            'line_total' => ['type' => ['number', 'null']],
                            'product_id' => ['type' => ['integer', 'null']],
                            'variant_id' => ['type' => ['integer', 'null']],
                            'color_id' => ['type' => ['integer', 'null']],
                        ],
                        'required' => [
                            'model',
                            'color',
                            'size',
                            'price',
                            'qty',
                            'line_total',
                            'product_id',
                            'variant_id',
                            'color_id',
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'missing_slots' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'delivery_fields' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => ['string', 'null']],
                        'phone' => ['type' => ['string', 'null']],
                        'city' => ['type' => ['string', 'null']],
                        'warehouse' => ['type' => ['string', 'null']],
                    ],
                    'required' => ['name', 'phone', 'city', 'warehouse'],
                    'additionalProperties' => false,
                ],
            ],
            'required' => [
                'action',
                'reply',
                'stage',
                'last_intent',
                'intent_purchase',
                'requires_human',
                'model_phrase',
                'selected_size',
                'selected_color',
                'selected_product_id',
                'selected_variant_id',
                'gallery_items',
                'cart_items',
                'missing_slots',
                'delivery_fields',
            ],
            'additionalProperties' => false,
        ];
    }

    private function buildStateContext(
        ChatAiConversationState $state,
        ChatConversation $conversation,
        string $platform,
        ChatAiPromptVersion $promptVersion,
        string $inputText
    ): string {
        $shouldExposeMissingSlots = (bool) $state->intent_purchase
            || in_array($state->stage, [self::STAGE_CHECKOUT_READY, self::STAGE_CHECKOUT], true);

        $currentInputMap = $this->resolveCurrentInputMap($inputText, $state);

        $context = [
            'conversation_id' => $conversation->id,
            'platform' => $platform,
            'current_input_text' => $inputText,
            'current_input_map' => $currentInputMap,
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

    /**
     * @param  array<int, array{role:string,content:string}>  $messages
     * @param  array{prompt_tokens:?int,completion_tokens:?int,total_tokens:?int}  $usage
     * @return array{0:string,1:array{prompt_tokens:?int,completion_tokens:?int,total_tokens:?int},2:array<string,mixed>,3:array<string,mixed>,4:string,5:string,6:array<int,array<string,mixed>>}
     */
    private function prepareStructuredResponse(
        ChatAiAgent $agent,
        array $messages,
        string $rawOutput,
        array $usage,
        ChatAiConversationState $state,
        string $stageBefore,
        string $inputText
    ): array {
        $attempt = 0;

        while (true) {
            $normalized = $this->normalizeModelPayload($this->decodeModelJson($rawOutput));
            $slotPatch = $this->buildSlotPatch($state, $normalized, $inputText);
            $nextStage = $this->resolveNextStage($stageBefore, $normalized['stage'], $slotPatch, (string) ($normalized['action'] ?? 'text'));
            $reply = (string) ($normalized['reply'] ?? '');
            $reply = $this->enforceCheckoutReplyConsistency($reply, $normalized, $slotPatch);
            $reply = $this->buildSafeReply($reply, $nextStage, $slotPatch);
            $mediaAttachments = $this->resolveAiMediaAttachments($inputText, $normalized, $state, $slotPatch);

            if (!$this->shouldRetryForEmptyReply($normalized, $reply, $mediaAttachments, $attempt)) {
                return [$rawOutput, $usage, $normalized, $slotPatch, $nextStage, $reply, $mediaAttachments];
            }

            [$rawOutput, $usage] = $this->repairEmptyReplyResponse($agent, $messages, $rawOutput, $inputText);
            $attempt++;
        }
    }

    /**
     * @return array{
     *   product_id:int,
     *   variant_id:?int,
     *   color_id:?int,
     *   size_hint:?string,
     *   model_phrase:string,
     *   item_code:?string,
     *   collage_url:?string
     * }|null
     */
    private function resolveCurrentInputMap(string $inputText, ChatAiConversationState $state): ?array
    {
        $selectedModelPhrase = $this->cleanNullableString($state->slots_json['selected_model_phrase'] ?? null);

        return $this->chatAiKnowledgeService->resolveMappedProduct($inputText, $selectedModelPhrase);
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
        $model = $this->resolveAgentModel($agent);

        $payload = array_merge([
            'model' => $model,
            'messages' => $messages,
            'temperature' => (float) ($agent->temperature ?? 0.3),
            'response_format' => $this->structuredOutputResponseFormat(),
        ], $this->buildTokensPayload($model, $this->resolveStructuredMaxTokens($agent)));

        if ((bool) config('services.openai.store', false)) {
            $payload['store'] = true;
        }

        $response = $this->performOpenAiRequestWithFallbacks($baseUrl, $apiKey, $timeout, $payload);

        if ($response->failed()) {
            throw new \RuntimeException(
                'OpenAI HTTP ' . $response->status() . ': ' . Str::limit((string) $response->body(), 500)
            );
        }

        $json = $response->json();
        $content = data_get($json, 'choices.0.message.content');
        $raw = $this->normalizeOpenAiContent($content);
        $initialUsage = [
            'prompt_tokens' => $this->nullableInt(data_get($json, 'usage.prompt_tokens')),
            'completion_tokens' => $this->nullableInt(data_get($json, 'usage.completion_tokens')),
            'total_tokens' => $this->nullableInt(data_get($json, 'usage.total_tokens')),
        ];
        $repairUsage = [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
        ];

        if (trim($raw) === '') {
            [$raw, $fallbackUsage] = $this->retryWithFallbackModelOnEmptyContent(
                $baseUrl,
                $apiKey,
                $timeout,
                $agent,
                $payload
            );

            if (trim($raw) === '') {
                throw new \RuntimeException('OpenAI повернув порожній content.');
            }

            $initialUsage = [
                'prompt_tokens' => $this->sumNullableInts(
                    $initialUsage['prompt_tokens'],
                    $this->nullableInt($fallbackUsage['prompt_tokens'] ?? null)
                ),
                'completion_tokens' => $this->sumNullableInts(
                    $initialUsage['completion_tokens'],
                    $this->nullableInt($fallbackUsage['completion_tokens'] ?? null)
                ),
                'total_tokens' => $this->sumNullableInts(
                    $initialUsage['total_tokens'],
                    $this->nullableInt($fallbackUsage['total_tokens'] ?? null)
                ),
            ];
        }

        if (!$this->isValidModelJson($raw)) {
            Log::warning('Chat AI: сирий JSON моделі невалідний, запускаю repair.', [
                'raw_preview' => Str::limit($raw, 800),
            ]);

            try {
                [$raw, $repairUsage] = $this->repairModelJson($baseUrl, $apiKey, $timeout, $agent, $raw);
            } catch (\Throwable $repairError) {
                Log::warning('Chat AI: repair не відновив JSON, запускаю повторну генерацію.', [
                    'error' => $repairError->getMessage(),
                    'raw_preview' => Str::limit($raw, 800),
                ]);

                [$raw, $repairUsage] = $this->retryStructuredJson($baseUrl, $apiKey, $timeout, $agent, $messages, $raw);
            }
        }

        return [
            $raw,
            [
                'prompt_tokens' => $this->sumNullableInts(
                    $initialUsage['prompt_tokens'],
                    $this->nullableInt($repairUsage['prompt_tokens'] ?? null)
                ),
                'completion_tokens' => $this->sumNullableInts(
                    $initialUsage['completion_tokens'],
                    $this->nullableInt($repairUsage['completion_tokens'] ?? null)
                ),
                'total_tokens' => $this->sumNullableInts(
                    $initialUsage['total_tokens'],
                    $this->nullableInt($repairUsage['total_tokens'] ?? null)
                ),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $originalPayload
     * @return array{0:string,1:array{prompt_tokens:?int,completion_tokens:?int,total_tokens:?int}}
     */
    private function retryWithFallbackModelOnEmptyContent(
        string $baseUrl,
        string $apiKey,
        int $timeout,
        ChatAiAgent $agent,
        array $originalPayload
    ): array {
        $primaryModel = $this->resolveAgentModel($agent);
        $fallbackModel = $this->resolveFallbackModel($primaryModel);
        if ($fallbackModel === null) {
            return ['', ['prompt_tokens' => null, 'completion_tokens' => null, 'total_tokens' => null]];
        }

        $fallbackPayload = $originalPayload;
        $fallbackPayload['model'] = $fallbackModel;

        unset($fallbackPayload['max_tokens'], $fallbackPayload['max_completion_tokens']);
        $fallbackPayload = array_merge(
            $fallbackPayload,
            $this->buildTokensPayload($fallbackModel, $this->resolveStructuredMaxTokens($agent))
        );

        $fallbackResponse = $this->performOpenAiRequestWithFallbacks($baseUrl, $apiKey, $timeout, $fallbackPayload);
        if ($fallbackResponse->failed()) {
            return ['', ['prompt_tokens' => null, 'completion_tokens' => null, 'total_tokens' => null]];
        }

        $fallbackJson = $fallbackResponse->json();
        $fallbackContent = data_get($fallbackJson, 'choices.0.message.content');

        return [
            $this->normalizeOpenAiContent($fallbackContent),
            [
                'prompt_tokens' => $this->nullableInt(data_get($fallbackJson, 'usage.prompt_tokens')),
                'completion_tokens' => $this->nullableInt(data_get($fallbackJson, 'usage.completion_tokens')),
                'total_tokens' => $this->nullableInt(data_get($fallbackJson, 'usage.total_tokens')),
            ],
        ];
    }

    private function resolveFallbackModel(string $primaryModel): ?string
    {
        $configured = trim((string) config('services.openai.empty_content_fallback_model', 'gpt-4.1-mini'));
        if ($configured === '') {
            return null;
        }

        return mb_strtolower($configured) === mb_strtolower($primaryModel)
            ? null
            : $configured;
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

    private function performOpenAiRequestWithFallbacks(
        string $baseUrl,
        string $apiKey,
        int $timeout,
        array $payload
    ): \Illuminate\Http\Client\Response {
        $attempt = 0;

        while (true) {
            $response = $this->performOpenAiRequest($baseUrl, $apiKey, $timeout, $payload);
            if ($response->successful() || $attempt >= 3) {
                return $response;
            }

            $attempt++;
            $errorBody = mb_strtolower((string) $response->body());
            $fallbackApplied = false;

            if (
                isset($payload['response_format'])
                && (
                    str_contains($errorBody, 'response_format')
                    || str_contains($errorBody, 'json_schema')
                    || str_contains($errorBody, 'json_object')
                )
            ) {
                if (data_get($payload, 'response_format.type') === 'json_schema') {
                    $payload['response_format'] = ['type' => 'json_object'];
                } else {
                    unset($payload['response_format']);
                }
                $fallbackApplied = true;
            }

            if (
                !$fallbackApplied
                && isset($payload['temperature'])
                && str_contains($errorBody, 'temperature')
                && str_contains($errorBody, 'supported')
            ) {
                unset($payload['temperature']);
                $fallbackApplied = true;
            }

            if (
                !$fallbackApplied
                && isset($payload['max_tokens'])
                && str_contains($errorBody, 'max_tokens')
                && str_contains($errorBody, 'max_completion_tokens')
            ) {
                $payload['max_completion_tokens'] = $payload['max_tokens'];
                unset($payload['max_tokens']);
                $fallbackApplied = true;
            }

            if (
                !$fallbackApplied
                && isset($payload['max_completion_tokens'])
                && str_contains($errorBody, 'max_completion_tokens')
                && (
                    str_contains($errorBody, 'unknown parameter')
                    || str_contains($errorBody, 'unsupported')
                )
            ) {
                $payload['max_tokens'] = $payload['max_completion_tokens'];
                unset($payload['max_completion_tokens']);
                $fallbackApplied = true;
            }

            if (!$fallbackApplied) {
                return $response;
            }
        }
    }

    private function resolveAgentModel(ChatAiAgent $agent): string
    {
        return (string) ($agent->model ?: config('services.openai.model', 'gpt-5-mini'));
    }

    /**
     * @return array{max_tokens?:int,max_completion_tokens?:int}
     */
    private function buildTokensPayload(string $model, int $maxTokens): array
    {
        $normalizedMaxTokens = max(1, $maxTokens);

        if ($this->modelRequiresMaxCompletionTokens($model)) {
            return ['max_completion_tokens' => $normalizedMaxTokens];
        }

        return ['max_tokens' => $normalizedMaxTokens];
    }

    private function modelRequiresMaxCompletionTokens(string $model): bool
    {
        $normalizedModel = mb_strtolower(trim($model));

        return str_starts_with($normalizedModel, 'gpt-5')
            || preg_match('/^o\d/', $normalizedModel) === 1;
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
        foreach ($this->jsonCandidates($raw) as $candidate) {
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            if (preg_match('/\{.*\}/s', $candidate, $matches) === 1) {
                $extracted = $this->sanitizeJsonCandidate((string) $matches[0]);
                $decoded = json_decode($extracted, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        throw new \RuntimeException('Відповідь моделі не є валідним JSON.');
    }

    /**
     * @return array{0:string,1:array{prompt_tokens:?int,completion_tokens:?int,total_tokens:?int}}
     */
    private function repairModelJson(
        string $baseUrl,
        string $apiKey,
        int $timeout,
        ChatAiAgent $agent,
        string $raw
    ): array {
        $model = $this->resolveAgentModel($agent);
        $payload = array_merge([
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => implode("\n", [
                        'Ти виправляєш відповідь іншої моделі до валідного JSON.',
                        'Поверни тільки JSON без markdown і без пояснень.',
                        'Допустимі action: text, send_product_photo, send_product_gallery, send_collage, ask_clarifying, checkout_request, none.',
                        'Обовʼязкові ключі верхнього рівня: action, reply, stage, last_intent, intent_purchase, requires_human, model_phrase, selected_size, selected_color, selected_product_id, selected_variant_id, gallery_items, cart_items, missing_slots, delivery_fields.',
                        'gallery_items має бути масивом обʼєктів з ключами product_id, variant_id, color_id, color.',
                        'cart_items має бути масивом обʼєктів.',
                        'delivery_fields має бути обʼєктом з ключами name, phone, city, warehouse.',
                        'У полі reply не виводь внутрішні ідентифікатори CRM (product_id, variant_id, color_id, media_id та подібні).',
                    ]),
                ],
                [
                    'role' => 'user',
                    'content' => "Виправ до валідного JSON цей текст:\n\n" . $raw,
                ],
            ],
            'temperature' => 0,
            'response_format' => $this->structuredOutputResponseFormat(),
        ], $this->buildTokensPayload($model, max(500, $this->resolveStructuredMaxTokens($agent))));

        $response = $this->performOpenAiRequestWithFallbacks($baseUrl, $apiKey, $timeout, $payload);

        if ($response->failed()) {
            throw new \RuntimeException(
                'Не вдалося відремонтувати JSON-відповідь моделі. OpenAI HTTP '
                . $response->status() . ': ' . Str::limit((string) $response->body(), 500)
            );
        }

        $json = $response->json();
        $content = data_get($json, 'choices.0.message.content');
        $repairedRaw = $this->normalizeOpenAiContent($content);

        if (!$this->isValidModelJson($repairedRaw)) {
            throw new \RuntimeException('Відповідь моделі не є валідним JSON навіть після repair.');
        }

        return [
            $repairedRaw,
            [
                'prompt_tokens' => $this->nullableInt(data_get($json, 'usage.prompt_tokens')),
                'completion_tokens' => $this->nullableInt(data_get($json, 'usage.completion_tokens')),
                'total_tokens' => $this->nullableInt(data_get($json, 'usage.total_tokens')),
            ],
        ];
    }

    /**
     * @return array{0:string,1:array{prompt_tokens:?int,completion_tokens:?int,total_tokens:?int}}
     */
    private function retryStructuredJson(
        string $baseUrl,
        string $apiKey,
        int $timeout,
        ChatAiAgent $agent,
        array $messages,
        string $raw
    ): array {
        $retryMessages = $messages;
        $retryMessages[] = [
            'role' => 'system',
            'content' => implode("\n", [
                'Попередня спроба повернула невалідний JSON.',
                'Повтори відповідь заново.',
                'Поверни тільки валідний JSON без markdown, без пояснень і без зайвого тексту.',
                'Якщо не вистачає впевненості, залиш невідомі поля null або [].',
                'Якщо клієнт просить кілька фото, використовуй action=send_product_gallery.',
                'Якщо клієнт просить одне фото, використовуй action=send_product_photo.',
                'Якщо клієнт просто пише текстове уточнення, використовуй action=text або ask_clarifying.',
            ]),
        ];
        $retryMessages[] = [
            'role' => 'assistant',
            'content' => 'Невдала попередня сира відповідь: ' . Str::limit($raw, 1200),
        ];

        $model = $this->resolveAgentModel($agent);
        $payload = array_merge([
            'model' => $model,
            'messages' => $retryMessages,
            'temperature' => 0,
            'response_format' => $this->structuredOutputResponseFormat(),
        ], $this->buildTokensPayload($model, max(700, $this->resolveStructuredMaxTokens($agent))));

        $response = $this->performOpenAiRequestWithFallbacks($baseUrl, $apiKey, $timeout, $payload);

        if ($response->failed()) {
            throw new \RuntimeException(
                'Повторна генерація JSON не спрацювала. OpenAI HTTP '
                . $response->status() . ': ' . Str::limit((string) $response->body(), 500)
            );
        }

        $json = $response->json();
        $content = data_get($json, 'choices.0.message.content');
        $retriedRaw = $this->normalizeOpenAiContent($content);

        if (!$this->isValidModelJson($retriedRaw)) {
            throw new \RuntimeException('Відповідь моделі не є валідним JSON навіть після повторної генерації.');
        }

        return [
            $retriedRaw,
            [
                'prompt_tokens' => $this->nullableInt(data_get($json, 'usage.prompt_tokens')),
                'completion_tokens' => $this->nullableInt(data_get($json, 'usage.completion_tokens')),
                'total_tokens' => $this->nullableInt(data_get($json, 'usage.total_tokens')),
            ],
        ];
    }

    /**
     * @param  array<int, array{role:string,content:string}>  $messages
     * @return array{0:string,1:array{prompt_tokens:?int,completion_tokens:?int,total_tokens:?int}}
     */
    private function repairEmptyReplyResponse(
        ChatAiAgent $agent,
        array $messages,
        string $raw,
        string $inputText
    ): array {
        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        $timeout = max(5, (int) config('services.openai.timeout', 30));
        $apiKey = (string) config('services.openai.api_key');

        $retryMessages = $messages;
        $retryMessages[] = [
            'role' => 'system',
            'content' => implode("\n", [
                'Попередня спроба повернула порожню або занадто агресивно санітизовану відповідь.',
                'Згенеруй відповідь заново у валідному JSON.',
                'Якщо клієнт не просить медіа, не повертай порожній reply.',
                'Для звичайних питань про ціну, розмір, наявність, характеристики, доставку або оформлення використовуй action=text і непорожній reply.',
                'Для медіа-дій reply може бути порожнім або дуже коротким.',
                'Не повертай action=none на нормальне повідомлення клієнта: ' . $inputText,
            ]),
        ];
        $retryMessages[] = [
            'role' => 'assistant',
            'content' => 'Попередня сира відповідь, яку треба виправити: ' . Str::limit($raw, 1200),
        ];

        $model = $this->resolveAgentModel($agent);
        $payload = array_merge([
            'model' => $model,
            'messages' => $retryMessages,
            'temperature' => 0,
            'response_format' => $this->structuredOutputResponseFormat(),
        ], $this->buildTokensPayload($model, max(700, $this->resolveStructuredMaxTokens($agent))));

        $response = $this->performOpenAiRequestWithFallbacks($baseUrl, $apiKey, $timeout, $payload);

        if ($response->failed()) {
            throw new \RuntimeException(
                'Не вдалося перегенерувати непорожню JSON-відповідь. OpenAI HTTP '
                . $response->status() . ': ' . Str::limit((string) $response->body(), 500)
            );
        }

        $json = $response->json();
        $content = data_get($json, 'choices.0.message.content');
        $retriedRaw = $this->normalizeOpenAiContent($content);

        if (!$this->isValidModelJson($retriedRaw)) {
            throw new \RuntimeException('Повторна генерація після порожнього reply повернула невалідний JSON.');
        }

        return [
            $retriedRaw,
            [
                'prompt_tokens' => $this->nullableInt(data_get($json, 'usage.prompt_tokens')),
                'completion_tokens' => $this->nullableInt(data_get($json, 'usage.completion_tokens')),
                'total_tokens' => $this->nullableInt(data_get($json, 'usage.total_tokens')),
            ],
        ];
    }

    private function isValidModelJson(string $raw): bool
    {
        try {
            $this->decodeModelJson($raw);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<int, string>
     */
    private function jsonCandidates(string $raw): array
    {
        $base = $this->sanitizeJsonCandidate($raw);
        $candidates = [$base];

        $decodedString = json_decode($base, true);
        if (is_string($decodedString) && $decodedString !== '') {
            $candidates[] = $this->sanitizeJsonCandidate($decodedString);
        }

        $withoutTrailingCommas = preg_replace('/,\s*([}\]])/', '$1', $base) ?? $base;
        if ($withoutTrailingCommas !== $base) {
            $candidates[] = $withoutTrailingCommas;
        }

        return array_values(array_unique(array_filter($candidates, static fn ($candidate) => trim($candidate) !== '')));
    }

    private function sanitizeJsonCandidate(string $raw): string
    {
        $trimmed = trim($raw);
        $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;
        $trimmed = str_replace(["\xEF\xBB\xBF", '“', '”', '’'], ['', '"', '"', "'"], $trimmed);

        return trim($trimmed);
    }

    /**
     * @param  array<string,mixed>  $normalized
     * @param  array<int,array<string,mixed>>  $mediaAttachments
     */
    private function shouldRetryForEmptyReply(array $normalized, string $reply, array $mediaAttachments, int $attempt): bool
    {
        if ($attempt > 0 || $reply !== '' || $mediaAttachments !== []) {
            return false;
        }

        return true;
    }

    private function resolveStructuredMaxTokens(ChatAiAgent $agent): int
    {
        return max(500, (int) ($agent->max_output_tokens ?? 300));
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
            'gallery_items' => $this->normalizeGalleryItems($payload['gallery_items'] ?? []),
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

    /**
     * @return array<int, array{product_id:?int,variant_id:?int,color_id:?int,color:?string}>
     */
    private function normalizeGalleryItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $productId = $this->nullableInt($item['product_id'] ?? $item['selected_product_id'] ?? null);
            $variantId = $this->nullableInt($item['variant_id'] ?? $item['selected_variant_id'] ?? null);
            $colorId = $this->nullableInt($item['color_id'] ?? $item['selected_color_id'] ?? null);
            $color = $this->cleanNullableString($item['color'] ?? $item['selected_color'] ?? null);

            if ($variantId) {
                $variant = ProductVariant::query()
                    ->select(['id', 'product_id'])
                    ->find($variantId);
                if ($variant) {
                    $variantId = $variant->id;
                    $productId = $variant->product_id ?: $productId;
                } else {
                    $variantId = null;
                }
            }

            if ($colorId === null && $color !== null) {
                $colorId = $this->resolveColorId($color, $color);
            }

            if ($productId !== null && $colorId === null) {
                $productColorId = Product::query()
                    ->whereKey($productId)
                    ->value('color_id');
                if ($productColorId) {
                    $colorId = (int) $productColorId;
                }
            }

            if ($productId === null && $variantId === null) {
                continue;
            }

            $normalized[] = [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'color_id' => $colorId,
                'color' => $color,
            ];
        }

        $unique = [];
        foreach ($normalized as $item) {
            $key = implode('|', [
                $item['product_id'] ?? 'null',
                $item['variant_id'] ?? 'null',
                $item['color_id'] ?? 'null',
                mb_strtolower((string) ($item['color'] ?? '')),
            ]);
            $unique[$key] = $item;
        }

        return array_values($unique);
    }

    /**
     * Для send_product_photo перший gallery item є найточнішим вибором поточного ходу AI.
     *
     * @param  array<string, mixed>  $normalized
     * @return array{product_id:int,variant_id:?int,color_id:?int}|null
     */
    private function resolvePrimaryGallerySelection(array $normalized, ?string $modelPhrase = null): ?array
    {
        foreach (($normalized['gallery_items'] ?? []) as $galleryItem) {
            if (!is_array($galleryItem)) {
                continue;
            }

            $productId = $this->nullableInt($galleryItem['product_id'] ?? null);
            $variantId = $this->nullableInt($galleryItem['variant_id'] ?? null);
            $colorId = $this->nullableInt($galleryItem['color_id'] ?? null);
            $color = $this->cleanNullableString($galleryItem['color'] ?? null);

            if ($variantId) {
                $variant = ProductVariant::query()
                    ->select(['id', 'product_id'])
                    ->find($variantId);

                if ($variant) {
                    $variantId = $variant->id;
                    $productId = $variant->product_id ?: $productId;
                } else {
                    $variantId = null;
                }
            }

            if ($productId === null && $modelPhrase !== null) {
                $resolved = $this->chatAiKnowledgeService->resolveProductForModelColor(
                    $modelPhrase,
                    $colorId,
                    $color
                );

                if ($resolved !== null) {
                    $productId = $this->nullableInt($resolved['product_id'] ?? null);
                    $variantId = $variantId ?: $this->nullableInt($resolved['variant_id'] ?? null);
                    $colorId = $colorId ?: $this->nullableInt($resolved['color_id'] ?? null);
                }
            }

            if ($productId !== null && $colorId === null) {
                $productColorId = Product::query()
                    ->whereKey($productId)
                    ->value('color_id');
                if ($productColorId) {
                    $colorId = (int) $productColorId;
                }
            }

            if ($productId !== null) {
                return [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'color_id' => $colorId,
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $galleryItems
     * @return array<int, array<string, mixed>>
     */
    private function resolveAttachmentsFromGalleryItems(array $galleryItems, ?string $modelPhrase = null): array
    {
        $attachments = [];
        $seenUrls = [];

        foreach ($galleryItems as $galleryItem) {
            if (!is_array($galleryItem)) {
                continue;
            }

            $galleryProductId = $this->nullableInt($galleryItem['product_id'] ?? null);
            $galleryVariantId = $this->nullableInt($galleryItem['variant_id'] ?? null);
            $galleryColorId = $this->nullableInt($galleryItem['color_id'] ?? null);
            $galleryColor = $this->cleanNullableString($galleryItem['color'] ?? null);

            if ($galleryProductId === null && $modelPhrase !== null) {
                $resolvedGalleryItem = $this->chatAiKnowledgeService->resolveProductForModelColor(
                    $modelPhrase,
                    $galleryColorId,
                    $galleryColor
                );
                if ($resolvedGalleryItem !== null) {
                    $galleryProductId = $this->nullableInt($resolvedGalleryItem['product_id'] ?? null);
                    $galleryVariantId = $galleryVariantId ?: $this->nullableInt($resolvedGalleryItem['variant_id'] ?? null);
                    $galleryColorId = $galleryColorId ?: $this->nullableInt($resolvedGalleryItem['color_id'] ?? null);
                }
            }

            $attachment = $this->resolveProductMediaAttachmentBySelection(
                $galleryProductId,
                $galleryVariantId,
                $galleryColorId
            );

            if ($attachment === null) {
                continue;
            }

            $attachmentMeta = data_get($attachment, 'stored_attachment.meta', []);
            $attachmentProductId = $this->nullableInt(data_get($attachmentMeta, 'product_id'));
            $attachmentVariantId = $this->nullableInt(data_get($attachmentMeta, 'variant_id'));
            $attachmentColorId = $this->nullableInt(data_get($attachmentMeta, 'color_id'));

            if ($galleryProductId !== null && $attachmentProductId !== $galleryProductId) {
                continue;
            }

            if ($galleryVariantId !== null && $attachmentVariantId !== $galleryVariantId) {
                continue;
            }

            if ($galleryColorId !== null && $attachmentColorId !== $galleryColorId) {
                continue;
            }

            $url = (string) data_get($attachment, 'stored_attachment.url', '');
            if ($url === '' || isset($seenUrls[$url])) {
                continue;
            }

            $seenUrls[$url] = true;
            $attachments[] = $attachment;
        }

        return $attachments;
    }

    private function buildSlotPatch(ChatAiConversationState $state, array $normalized, string $inputText): array
    {
        $action = (string) ($normalized['action'] ?? 'text');
        $slots = is_array($state->slots_json) ? $state->slots_json : [];
        $delivery = is_array($slots['delivery'] ?? null) ? $slots['delivery'] : [];
        $cartItems = $this->normalizeCartItems($slots['cart_items'] ?? []);
        $incomingDelivery = $normalized['delivery_fields'] ?? [];
        $parsedDelivery = $this->shouldInferDeliveryFields($inputText, $state, $normalized)
            ? $this->inferDeliveryFieldsFromInput($inputText, $delivery)
            : [];

        foreach (['name', 'phone', 'city', 'warehouse'] as $field) {
            $value = $this->cleanNullableString($incomingDelivery[$field] ?? null)
                ?: $this->cleanNullableString($parsedDelivery[$field] ?? null);
            if ($value !== null) {
                $delivery[$field] = $value;
            }
        }

        if ($delivery !== []) {
            $slots['delivery'] = $delivery;
        }

        $stateSelectedProductId = $state->selected_product_id;
        $stateSelectedVariantId = $state->selected_variant_id;
        $stateSelectedColorId = $state->selected_color_id;
        $stateSelectedSize = $state->selected_size;

        $selectedProductId = $stateSelectedProductId;
        $selectedVariantId = $stateSelectedVariantId;
        $selectedColorId = $stateSelectedColorId;
        $selectedSize = $stateSelectedSize;
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

            if (!empty($mapped['product_id'])) {
                $selectedProductId = (int) $mapped['product_id'];
            }

            if (!empty($mapped['variant_id'])) {
                $selectedVariantId = (int) $mapped['variant_id'];
            }

            if (!empty($mapped['color_id'])) {
                $selectedColorId = (int) $mapped['color_id'];
            }

            if (!empty($mapped['size_hint'])) {
                $selectedSize = (string) $mapped['size_hint'];
            }
        }

        $isExplicitMappedItemCodeInput = $mapped
            && $this->isExplicitMappedItemCodeInput($inputText, (string) ($mapped['item_code'] ?? ''));

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

        $primaryGallerySelection = $action === 'send_product_photo'
            ? $this->resolvePrimaryGallerySelection($normalized, $selectedModelPhrase)
            : null;
        if (
            $primaryGallerySelection !== null
            && $candidateProductId === null
            && $candidateVariantId === null
        ) {
            $selectedProductId = $this->nullableInt($primaryGallerySelection['product_id'] ?? null) ?: $selectedProductId;
            $selectedVariantId = $this->nullableInt($primaryGallerySelection['variant_id'] ?? null) ?: $selectedVariantId;
            $selectedColorId = $this->nullableInt($primaryGallerySelection['color_id'] ?? null) ?: $selectedColorId;
        }

        $productChanged = $selectedProductId !== null
            && $selectedProductId !== $stateSelectedProductId;
        if ($productChanged) {
            // При перемиканні на інший товар/колір не тягнемо старий variant з попередньої позиції.
            $selectedVariantId = $candidateVariantId ?: null;
        }

        if (!$isExplicitMappedItemCodeInput) {
            $candidateSize = $this->normalizeSize($normalized['selected_size'])
                ?: $this->extractSizeFromText($inputText);
            if ($candidateSize !== null) {
                $selectedSize = $candidateSize;
            }
        }

        $resolvedColorId = $this->resolveColorId(
            $normalized['selected_color'] ?? null,
            $inputText
        );
        if ($resolvedColorId !== null) {
            $selectedColorId = $resolvedColorId;
        } elseif ($productChanged) {
            // Для нового товару колір беремо заново з поточного запиту або самого товару.
            $selectedColorId = null;
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

        $incomingCartItems = [];
        foreach (($normalized['cart_items'] ?? []) as $incomingItem) {
            $normalizedIncomingItem = $this->normalizeCartItem(is_array($incomingItem) ? $incomingItem : []);
            if ($normalizedIncomingItem !== null) {
                $incomingCartItems[] = $normalizedIncomingItem;
            }
        }

        $incomingCartItems = $this->filterIncomingCartItemsForCurrentMessage($incomingCartItems, $inputText);

        $replacedCartFromCurrentMessage = false;
        if ($incomingCartItems !== []) {
            if ($this->shouldReplaceExistingCartItems($inputText, $incomingCartItems, $cartItems)) {
                $cartItems = $this->normalizeCartItems($incomingCartItems);
                $replacedCartFromCurrentMessage = true;
            } else {
                foreach ($incomingCartItems as $incomingItem) {
                    $cartItems = $this->upsertCartItem($cartItems, $incomingItem);
                }
            }
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
            // Кошик лише заповнює порожні selection-поля, але не має перебивати новий вибір GPT.
            if ($selectedProductId === null || $replacedCartFromCurrentMessage) {
                $selectedProductId = $this->nullableInt($primaryItem['product_id'] ?? null);
            }
            if ($selectedVariantId === null || $replacedCartFromCurrentMessage) {
                $selectedVariantId = $this->nullableInt($primaryItem['variant_id'] ?? null);
            }
            if ($selectedColorId === null || $replacedCartFromCurrentMessage) {
                $selectedColorId = $this->nullableInt($primaryItem['color_id'] ?? null);
            }
            if ($selectedSize === null || $replacedCartFromCurrentMessage) {
                $selectedSize = $this->normalizeSize((string) ($primaryItem['size'] ?? ''));
            }
        }

        $intentPurchase = (bool) ($state->intent_purchase || $normalized['intent_purchase']);
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

        $requestedStage = (string) ($normalized['stage'] ?? $state->stage);
        $checkoutActionRequested = (string) ($normalized['action'] ?? 'text') === 'checkout_request';

        $hasSelectionContext = $selectedProductId !== null
            || $selectedVariantId !== null
            || $selectedColorId !== null
            || $selectedSize !== null
            || $hasCartItems;

        $hasCompleteSelection = $hasReadyCartItem
            || (
                $selectedProductId !== null
                && $selectedColorId !== null
                && $selectedSize !== null
            );

        $shouldTrackMissingSlots = (
            $checkoutActionRequested
            || in_array($requestedStage, [self::STAGE_CHECKOUT_READY, self::STAGE_CHECKOUT], true)
            || in_array($state->stage, [self::STAGE_CHECKOUT_READY, self::STAGE_CHECKOUT], true)
        ) && $hasCompleteSelection;

        $missingSlots = $shouldTrackMissingSlots
            ? $this->calculateMissingSlots([
                'selected_product_id' => $selectedProductId,
                'selected_variant_id' => $selectedVariantId,
                'selected_size' => $selectedSize,
                'intent_purchase' => $intentPurchase,
                'delivery' => $delivery,
                'cart_items' => $cartItems,
                'include_delivery' => $shouldTrackMissingSlots,
            ])
            : [];

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
            'has_selection_context' => $hasSelectionContext,
            'has_complete_selection' => $hasCompleteSelection,
        ];
    }

    private function resolveNextStage(string $currentStage, ?string $modelStage, array $slotPatch, string $action = 'text'): string
    {
        if (
            in_array($action, ['send_product_photo', 'send_product_gallery', 'send_collage'], true)
            && empty($slotPatch['intent_purchase'])
            && $currentStage === self::STAGE_INTEREST
        ) {
            return self::STAGE_INTEREST;
        }

        $stage = isset(self::STAGE_ORDER[$modelStage ?? '']) ? $modelStage : $currentStage;
        if (!isset(self::STAGE_ORDER[$stage])) {
            $stage = self::STAGE_INTEREST;
        }

        $hasSelectionContext = !empty($slotPatch['has_selection_context']);
        $hasCompleteSelection = !empty($slotPatch['has_complete_selection']);
        $checkoutActionRequested = $action === 'checkout_request';

        if ($checkoutActionRequested && !$hasCompleteSelection) {
            return $hasSelectionContext ? self::STAGE_SELECTION : self::STAGE_INTEREST;
        }

        if (
            in_array($action, ['text', 'ask_clarifying', 'send_product_photo', 'send_product_gallery'], true)
            && $hasSelectionContext
            && !in_array($stage, [self::STAGE_CHECKOUT_READY, self::STAGE_CHECKOUT], true)
        ) {
            return self::STAGE_SELECTION;
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
        $cleanReply = strip_tags(str_replace(["\r\n", "\r"], "\n", $reply));
        $cleanReply = $this->stripInternalIdentifiersFromReply($cleanReply);
        $cleanReply = $this->normalizeReplyFormatting($cleanReply);
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

    private function normalizeReplyFormatting(string $reply): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $reply);
        $lines = explode("\n", $normalized);
        $formattedLines = [];
        $previousBlank = true;

        foreach ($lines as $line) {
            $cleanLine = trim((string) preg_replace('/[ \t]+/u', ' ', $line));
            $cleanLine = preg_replace('/[ \t]+([,.;:!?])/u', '$1', $cleanLine) ?? $cleanLine;

            if ($cleanLine === '') {
                if (!$previousBlank && $formattedLines !== []) {
                    $formattedLines[] = '';
                }

                $previousBlank = true;

                continue;
            }

            $formattedLines[] = $cleanLine;
            $previousBlank = false;
        }

        return trim(implode("\n", $formattedLines));
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $slotPatch
     * @return array<string, mixed>|null
     */
    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveAiMediaAttachments(
        string $inputText,
        array $normalized,
        ChatAiConversationState $state,
        array $slotPatch
    ): array {
        $action = (string) ($normalized['action'] ?? 'text');
        if (!$this->actionRequiresMedia($action)) {
            return [];
        }

        if ($action === 'send_collage') {
            $galleryModelPhrase = $this->cleanNullableString($normalized['model_phrase'] ?? null)
                ?: $this->cleanNullableString($slotPatch['selected_model_phrase'] ?? null)
                ?: $this->cleanNullableString($state->slots_json['selected_model_phrase'] ?? null);
            $galleryItems = $normalized['gallery_items'] ?? [];
            $galleryAttachments = $this->resolveAttachmentsFromGalleryItems(
                $galleryItems,
                $galleryModelPhrase
            );
            if ($galleryAttachments !== [] || !empty($galleryItems)) {
                return $galleryAttachments;
            }

            $collageAttachments = $this->resolveCollageMediaAttachments($inputText, $normalized, $state, $slotPatch);
            if ($collageAttachments !== []) {
                return $collageAttachments;
            }
        }

        if ($action === 'send_product_gallery') {
            $galleryModelPhrase = $this->cleanNullableString($normalized['model_phrase'] ?? null)
                ?: $this->cleanNullableString($slotPatch['selected_model_phrase'] ?? null)
                ?: $this->cleanNullableString($state->slots_json['selected_model_phrase'] ?? null);
            $galleryItems = $normalized['gallery_items'] ?? [];
            $attachments = $this->resolveAttachmentsFromGalleryItems(
                $galleryItems,
                $galleryModelPhrase
            );

            if ($attachments !== [] || !empty($galleryItems)) {
                return $attachments;
            }
        }

        $singleAttachment = $this->resolveAiMediaAttachment($inputText, $normalized, $state, $slotPatch);

        return $singleAttachment !== null ? [$singleAttachment] : [];
    }

    private function prependGreetingIfNeeded(string $reply, int $conversationId): string
    {
        // Вітальне повідомлення тимчасово вимкнене за запитом бізнесу.
        return trim($reply);
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $slotPatch
     * @return array<int, array<string, mixed>>
     */
    private function resolveCollageMediaAttachments(
        string $inputText,
        array $normalized,
        ChatAiConversationState $state,
        array $slotPatch
    ): array {
        $attachments = [];
        $seenUrls = [];

        $isBroadCollageIntent = (string) ($normalized['action'] ?? '') === 'send_collage'
            && $this->cleanNullableString($normalized['model_phrase'] ?? null) === null
            && $this->nullableInt($normalized['selected_product_id'] ?? null) === null
            && $this->nullableInt($normalized['selected_variant_id'] ?? null) === null
            && empty($normalized['gallery_items'] ?? []);

        $productId = $isBroadCollageIntent
            ? null
            : ($this->nullableInt($slotPatch['selected_product_id'] ?? null) ?: $state->selected_product_id);
        $variantId = $isBroadCollageIntent
            ? null
            : ($this->nullableInt($slotPatch['selected_variant_id'] ?? null) ?: $state->selected_variant_id);
        $colorId = $isBroadCollageIntent
            ? null
            : ($this->nullableInt($slotPatch['selected_color_id'] ?? null) ?: $state->selected_color_id);
        $modelPhrase = $isBroadCollageIntent
            ? null
            : (
                $this->cleanNullableString($normalized['model_phrase'] ?? null)
                ?: $this->cleanNullableString($slotPatch['selected_model_phrase'] ?? null)
                ?: $this->cleanNullableString($state->slots_json['selected_model_phrase'] ?? null)
            );

        // Для broad send_collage (без конкретної моделі) відправляємо всі доступні підбірки моделей.
        if ($isBroadCollageIntent) {
            foreach ($this->chatAiKnowledgeService->productCatalogContext(20, 1) as $catalogModel) {
                foreach (($catalogModel['collage_urls'] ?? []) as $catalogCollageUrl) {
                    foreach ($this->explodeMediaUrls((string) $catalogCollageUrl) as $collageUrl) {
                        if ($collageUrl === '' || isset($seenUrls[$collageUrl])) {
                            continue;
                        }

                        $attachment = $this->buildMediaAttachmentPayload(
                            $collageUrl,
                            'image',
                            'collage',
                            ['product_id' => null, 'variant_id' => null, 'color_id' => null]
                        );

                        if ($attachment === null) {
                            continue;
                        }

                        $seenUrls[$collageUrl] = true;
                        $attachments[] = $attachment;
                    }
                }
            }

            if ($attachments !== []) {
                return $attachments;
            }
        }

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

        $rawCollageUrls = [];
        if ($mapped !== null) {
            $rawCollageUrls[] = (string) ($mapped['collage_url'] ?? '');
            if (!$productId && !empty($mapped['product_id'])) {
                $productId = (int) $mapped['product_id'];
            }
            if (!$variantId && !empty($mapped['variant_id'])) {
                $variantId = (int) $mapped['variant_id'];
            }
            if (!$colorId && !empty($mapped['color_id'])) {
                $colorId = (int) $mapped['color_id'];
            }
        }

        foreach ($rawCollageUrls as $rawCollageUrl) {
            foreach ($this->explodeMediaUrls($rawCollageUrl) as $collageUrl) {
                if ($collageUrl === '' || isset($seenUrls[$collageUrl])) {
                    continue;
                }

                $attachment = $this->buildMediaAttachmentPayload(
                    $collageUrl,
                    'image',
                    'collage',
                    ['product_id' => $productId, 'variant_id' => $variantId, 'color_id' => $colorId]
                );

                if ($attachment === null) {
                    continue;
                }

                $seenUrls[$collageUrl] = true;
                $attachments[] = $attachment;
            }
        }

        if ($attachments === []) {
            if ($modelPhrase === null && !$productId) {
                foreach ($this->chatAiKnowledgeService->productCatalogContext(20, 1) as $catalogModel) {
                    foreach (($catalogModel['collage_urls'] ?? []) as $catalogCollageUrl) {
                        foreach ($this->explodeMediaUrls((string) $catalogCollageUrl) as $collageUrl) {
                            if ($collageUrl === '' || isset($seenUrls[$collageUrl])) {
                                continue;
                            }

                            $attachment = $this->buildMediaAttachmentPayload(
                                $collageUrl,
                                'image',
                                'collage',
                                ['product_id' => null, 'variant_id' => null, 'color_id' => null]
                            );

                            if ($attachment === null) {
                                continue;
                            }

                            $seenUrls[$collageUrl] = true;
                            $attachments[] = $attachment;
                        }
                    }
                }
            }

            $mapForProduct = $this->chatAiKnowledgeService->resolveModelMapForProduct($productId, $colorId);
            if ($mapForProduct !== null) {
                foreach ($this->explodeMediaUrls((string) ($mapForProduct['collage_url'] ?? '')) as $collageUrl) {
                    if ($collageUrl === '' || isset($seenUrls[$collageUrl])) {
                        continue;
                    }

                    $attachment = $this->buildMediaAttachmentPayload(
                        $collageUrl,
                        'image',
                        'collage',
                        ['product_id' => $productId, 'variant_id' => $variantId, 'color_id' => $colorId]
                    );

                    if ($attachment === null) {
                        continue;
                    }

                    $seenUrls[$collageUrl] = true;
                    $attachments[] = $attachment;
                }
            }
        }

        return $attachments;
    }

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
        $modelPhrase = $this->cleanNullableString($normalized['model_phrase'] ?? null)
            ?: $this->cleanNullableString($slotPatch['selected_model_phrase'] ?? null)
            ?: $this->cleanNullableString($state->slots_json['selected_model_phrase'] ?? null);
        $stateModelPhrase = $this->cleanNullableString($state->slots_json['selected_model_phrase'] ?? null);
        $primaryGallerySelection = $action === 'send_product_photo'
            ? $this->resolvePrimaryGallerySelection($normalized, $modelPhrase)
            : null;
        $currentModelKey = $this->normalizePhraseKey($modelPhrase);
        $stateModelKey = $this->normalizePhraseKey($stateModelPhrase);
        $allowStateFallback = $primaryGallerySelection === null
            && (
                $currentModelKey === null
                || ($stateModelKey !== null && $currentModelKey === $stateModelKey)
            );

        $productId = $this->nullableInt($slotPatch['selected_product_id'] ?? null)
            ?: $this->nullableInt($primaryGallerySelection['product_id'] ?? null)
            ?: ($allowStateFallback ? $state->selected_product_id : null);
        $variantId = $this->nullableInt($slotPatch['selected_variant_id'] ?? null)
            ?: $this->nullableInt($primaryGallerySelection['variant_id'] ?? null)
            ?: ($allowStateFallback ? $state->selected_variant_id : null);
        $colorId = $this->nullableInt($slotPatch['selected_color_id'] ?? null)
            ?: $this->nullableInt($primaryGallerySelection['color_id'] ?? null)
            ?: ($allowStateFallback ? $state->selected_color_id : null);

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

    private function normalizePhraseKey(?string $value): ?string
    {
        $clean = $this->cleanNullableString($value);

        return $clean !== null ? mb_strtolower($clean) : null;
    }

    private function actionRequiresMedia(string $action): bool
    {
        return in_array($action, ['send_product_photo', 'send_product_gallery', 'send_collage'], true);
    }

    private function actionPrefersCollage(string $action): bool
    {
        return $action === 'send_collage';
    }

    private function normalizeAction(mixed $value): string
    {
        $action = $this->cleanNullableString(is_scalar($value) ? (string) $value : null);
        $allowed = ['text', 'send_product_photo', 'send_product_gallery', 'send_collage', 'ask_clarifying', 'checkout_request', 'none'];

        return $action !== null && in_array($action, $allowed, true)
            ? $action
            : 'text';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveProductMediaAttachmentBySelection(?int $productId, ?int $variantId, ?int $colorId): ?array
    {
        if (!$productId) {
            return null;
        }

        if (!$colorId) {
            $productColorId = Product::query()
                ->whereKey($productId)
                ->value('color_id');
            if ($productColorId) {
                $colorId = (int) $productColorId;
            }
        }

        $resolvedMedia = $this->findPreferredProductMedia($productId, $variantId, $colorId);
        if ($resolvedMedia !== null) {
            return $resolvedMedia;
        }

        $product = Product::query()
            ->select(['id', 'main_photo_path', 'color_id'])
            ->find($productId);

        if ($product && $product->main_photo_url) {
            return $this->buildMediaAttachmentPayload(
                $product->main_photo_url,
                'image',
                'main_photo',
                ['product_id' => $productId, 'variant_id' => $variantId, 'color_id' => $colorId ?: $product->color_id]
            );
        }

        return null;
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
        $clean = $this->explodeMediaUrls($url)[0] ?? null;
        if ($clean === null) {
            return null;
        }

        if (str_starts_with($clean, 'http://') || str_starts_with($clean, 'https://')) {
            return $clean;
        }

        return url(ltrim($clean, '/'));
    }

    /**
     * @return array<int, string>
     */
    private function explodeMediaUrls(mixed $value): array
    {
        $clean = $this->cleanNullableString($value);
        if ($clean === null) {
            return [];
        }

        $parts = preg_split('/[\r\n,]+/u', $clean) ?: [];
        $urls = [];
        $seen = [];

        foreach ($parts as $part) {
            $url = trim((string) $part);
            if ($url === '' || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $urls[] = $url;
        }

        return $urls;
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
        $cleanReply = preg_replace('/[ \t]{2,}/u', ' ', $cleanReply) ?? $cleanReply;
        $cleanReply = preg_replace('/[ \t]+([,.;:!?])/u', '$1', $cleanReply) ?? $cleanReply;
        $cleanReply = $this->stripInternalIdentifiersFromReply($cleanReply);
        $cleanReply = preg_replace('/:\s*$/u', '.', $cleanReply) ?? $cleanReply;

        return trim($cleanReply);
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    private function buildMediaFallbackReply(array $normalized, array $attachment): string
    {
        $action = (string) ($normalized['action'] ?? 'text');
        $source = (string) ($attachment['source'] ?? '');

        if ($action === 'send_collage' || $source === 'collage') {
            return 'Надсилаю колаж з доступними варіантами.';
        }

        if ($action === 'send_product_gallery') {
            return 'Надсилаю фото доступних варіантів.';
        }

        return 'Надсилаю фото товару.';
    }

    private function stripInternalIdentifiersFromReply(string $reply): string
    {
        $cleanReply = $reply;

        $patterns = [
            '/\b(?:product|variant|color)[ _-]?id\s*[:=#№]?\s*\d+\b/iu',
            '/\b(?:товар|варіант|колір)\s*id\s*[:=#№]?\s*\d+\b/iu',
            '/\bid\s*[:=#№]\s*\d{3,8}\b/iu',
            '/\(\s*\d{3,8}\s*\)/u',
            '/\[\s*\d{3,8}\s*\]/u',
        ];

        foreach ($patterns as $pattern) {
            $cleanReply = preg_replace($pattern, '', $cleanReply) ?? $cleanReply;
        }

        $cleanReply = preg_replace('/\(\s*[,;:]\s*/u', '(', $cleanReply) ?? $cleanReply;
        $cleanReply = preg_replace('/\s*[,;:]\s*\)/u', ')', $cleanReply) ?? $cleanReply;
        $cleanReply = preg_replace('/[ \t]{2,}/u', ' ', $cleanReply) ?? $cleanReply;
        $cleanReply = preg_replace('/,\s*,+/u', ', ', $cleanReply) ?? $cleanReply;
        $cleanReply = preg_replace('/\(\s*\)/u', '', $cleanReply) ?? $cleanReply;
        $cleanReply = preg_replace('/[ \t]+([,.;:!?])/u', '$1', $cleanReply) ?? $cleanReply;

        return trim($cleanReply, " \t\n\r\0\x0B,;");
    }

    private function containsDeliveryRequest(string $text): bool
    {
        $normalized = mb_strtolower($text);
        foreach ([
            'піб',
            'ім\'я',
            'ім’я',
            'прізвище',
            'телефон',
            'номер телефону',
            'місто',
            'адрес',
            'відділен',
            'поштомат',
            'нова пошта',
        ] as $needle) {
            if (mb_stripos($normalized, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Прибирає зайве повторення повної комбінації товару на старті відповіді
     * для коротких запитів про властивості (матеріал, хутро, підошва тощо).
     *
     * @param  array<string, mixed>  $normalized
     */
    private function compactReplyForPropertyQuestion(string $reply, string $inputText, array $normalized): string
    {
        if ($reply === '') {
            return '';
        }

        $action = (string) ($normalized['action'] ?? 'text');
        if (!in_array($action, ['text', 'ask_clarifying'], true)) {
            return $reply;
        }

        if (!$this->isProductPropertyQuestion($inputText)) {
            return $reply;
        }

        $patterns = [
            '/^\s*[^.!?\n]{0,180}\b\d{2}\s*\/\s*\d{2}\b\s*[—-]\s*/u',
            '/^\s*[^.!?\n]{30,180}\s*[—-]\s*/u',
        ];

        foreach ($patterns as $pattern) {
            $candidate = preg_replace($pattern, '', $reply, 1);
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return $reply;
    }

    private function isProductPropertyQuestion(string $text): bool
    {
        return preg_match(
            "/\b(мех|хутр|еко[- ]?хутр|натурал|матеріал|склад|підошв|якість|гумов|резин|м'яка|м'які)\b/ui",
            mb_strtolower($text)
        ) === 1;
    }

    /**
     * Захищає checkout-відповідь від передчасного підтвердження замовлення.
     *
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $slotPatch
     */
    private function enforceCheckoutReplyConsistency(string $reply, array $normalized, array $slotPatch): string
    {
        $action = (string) ($normalized['action'] ?? 'text');
        if ($action !== 'checkout_request') {
            return $reply;
        }

        $missingSlotsRaw = is_array($slotPatch['missing_slots_json'] ?? null)
            ? $slotPatch['missing_slots_json']
            : [];
        $missingSlots = array_values(array_filter(array_map(
            fn ($slot) => $this->cleanNullableString(is_scalar($slot) ? (string) $slot : null),
            $missingSlotsRaw
        )));
        $missingDelivery = array_values(array_intersect(['name', 'phone', 'city', 'warehouse'], $missingSlots));

        if ($missingDelivery !== []) {
            return $this->buildDeliveryRequestReply($missingDelivery);
        }

        $confirmation = $this->extractCheckoutConfirmationPart($reply);
        if ($confirmation !== '') {
            return $confirmation;
        }

        return 'Дякуємо, замовлення прийнято в обробку. Якщо буде потрібно, менеджер зв’яжеться з вами для уточнення деталей.';
    }

    /**
     * @param  array<int, string>  $missingDelivery
     */
    private function buildDeliveryRequestReply(array $missingDelivery): string
    {
        $labels = [
            'name' => 'ПІБ отримувача',
            'phone' => 'Номер телефону',
            'city' => 'Місто або населений пункт',
            'warehouse' => 'Номер відділення або поштомату Нової пошти',
        ];

        $items = [];
        foreach ($missingDelivery as $field) {
            if (isset($labels[$field])) {
                $items[] = '- ' . $labels[$field];
            }
        }

        if ($items === []) {
            return 'Для оформлення замовлення, будь ласка, надайте: ПІБ отримувача, номер телефону, місто та відділення або поштомат Нової пошти.';
        }

        if (count($items) === 1) {
            return 'Для оформлення замовлення, будь ласка, надайте: ' . ltrim($items[0], '- ') . '.';
        }

        return "Для оформлення замовлення, будь ласка, надайте:\n" . implode("\n", $items);
    }

    private function extractCheckoutConfirmationPart(string $reply): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $reply));
        if ($clean === '') {
            return '';
        }

        if ($this->containsDeliveryRequest($clean)) {
            return '';
        }

        if (preg_match('/підтвердження оформлення\s*[:\-]/ui', $clean, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $start = (int) ($matches[0][1] ?? 0);
            // preg_match повертає позицію у байтах, тому тут потрібен substr, а не mb_substr.
            $clean = trim((string) substr($clean, $start));
            $clean = preg_replace('/^підтвердження оформлення\s*[:\-]\s*/ui', '', $clean) ?? $clean;
        }

        return trim($clean);
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

    /**
     * Код лише підхоплює службові поля доставки, якщо модель їх не витягнула.
     *
     * @param  array<string, mixed>  $normalized
     */
    private function shouldInferDeliveryFields(string $inputText, ChatAiConversationState $state, array $normalized): bool
    {
        $normalizedText = mb_strtolower(trim($inputText));
        if ($normalizedText === '') {
            return false;
        }

        if (
            in_array($state->stage, [self::STAGE_CHECKOUT_READY, self::STAGE_CHECKOUT], true)
            || (string) ($normalized['action'] ?? 'text') === 'checkout_request'
        ) {
            return true;
        }

        return $this->looksLikeDeliveryPayload($normalizedText);
    }

    private function looksLikeDeliveryPayload(string $normalizedText): bool
    {
        if ($normalizedText === '') {
            return false;
        }

        if (preg_match('/\b(відділен|відд\.?|від\.?|поштомат|нова пошта)\b/ui', $normalizedText) === 1) {
            return true;
        }

        if (preg_match('/(?:\+?38)?\D*0\d{2}\D*\d{3}\D*\d{2}\D*\d{2}/u', $normalizedText) === 1) {
            return true;
        }

        return substr_count($normalizedText, ',') >= 2;
    }

    /**
     * @param  array<string, string|null>  $currentDelivery
     * @return array{name:?string,phone:?string,city:?string,warehouse:?string}
     */
    private function inferDeliveryFieldsFromInput(string $inputText, array $currentDelivery = []): array
    {
        $result = [
            'name' => null,
            'phone' => null,
            'city' => null,
            'warehouse' => null,
        ];

        $phone = $this->extractPhoneFromText($inputText);
        if ($phone !== null) {
            $result['phone'] = $phone;
        }

        $warehouse = $this->extractWarehouseFromText($inputText);
        if ($warehouse !== null) {
            $result['warehouse'] = $warehouse;
        }

        $segments = preg_split('/[\n,;]+/u', $inputText) ?: [];
        $segments = array_values(array_filter(array_map(
            fn (string $segment): string => trim($segment),
            $segments
        )));

        if ($segments === []) {
            return $result;
        }

        $nameCandidate = null;
        $cityCandidate = null;

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            if ($phone !== null && str_contains($segment, $phone)) {
                continue;
            }

            if ($warehouse !== null && mb_stripos($segment, (string) preg_replace('/\s+/u', ' ', $warehouse)) !== false) {
                continue;
            }

            if (preg_match('/\d/u', $segment) === 1) {
                continue;
            }

            $wordCount = count(array_values(array_filter(preg_split('/\s+/u', $segment) ?: [])));

            if ($nameCandidate === null && $wordCount >= 2 && $wordCount <= 4) {
                $nameCandidate = $segment;
                continue;
            }

            if ($cityCandidate === null && $wordCount >= 1 && $wordCount <= 4) {
                $cityCandidate = $segment;
            }
        }

        if ($this->cleanNullableString($currentDelivery['name'] ?? null) === null && $nameCandidate !== null) {
            $result['name'] = $nameCandidate;
        }

        if ($this->cleanNullableString($currentDelivery['city'] ?? null) === null) {
            if ($cityCandidate !== null && $cityCandidate !== $nameCandidate) {
                $result['city'] = $cityCandidate;
            } elseif ($nameCandidate === null && isset($segments[0]) && preg_match('/\d/u', $segments[0]) !== 1) {
                $result['city'] = $segments[0];
            }
        }

        return $result;
    }

    private function extractPhoneFromText(string $inputText): ?string
    {
        if (
            preg_match('/((?:\+?38)?\s*\(?0\d{2}\)?(?:[\s-]*\d){7})/u', $inputText, $matches) !== 1
            && preg_match('/((?:\+?38)?\D*0\d{2}\D*\d{3}\D*\d{2}\D*\d{2})/u', $inputText, $matches) !== 1
        ) {
            return null;
        }

        $digits = preg_replace('/\D+/u', '', (string) ($matches[1] ?? '')) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '38') && mb_strlen($digits) === 12) {
            return '+' . $digits;
        }

        if (mb_strlen($digits) === 10 && str_starts_with($digits, '0')) {
            return $digits;
        }

        return null;
    }

    private function extractWarehouseFromText(string $inputText): ?string
    {
        if (preg_match('/\b(поштомат)\s*№?\s*(\d{1,6})\b/ui', $inputText, $matches) === 1) {
            return 'Поштомат ' . trim((string) ($matches[2] ?? ''));
        }

        if (
            preg_match('/\b(відділення|відд\.?|від\.?)\s*№?\s*(\d{1,4})\b/ui', $inputText, $matches) === 1
            || preg_match('/\bнова пошта\s*№?\s*(\d{1,4})\b/ui', $inputText, $matches) === 1
        ) {
            return 'Відділення ' . trim((string) ($matches[2] ?? $matches[1] ?? ''));
        }

        return null;
    }

    private function isExplicitMappedItemCodeInput(string $text, string $itemCode): bool
    {
        $normalizedText = mb_strtolower(trim($text));
        $normalizedCode = trim($itemCode);

        if ($normalizedText === '' || $normalizedCode === '') {
            return false;
        }

        if ($normalizedText === $normalizedCode) {
            return true;
        }

        $patterns = [
            '/^\s*' . preg_quote($normalizedCode, '/') . '\s*(?:номер|код)\s*$/ui',
            '/^\s*(?:номер|код)\s*' . preg_quote($normalizedCode, '/') . '\s*$/ui',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalizedText) === 1) {
                return true;
            }
        }

        return false;
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
        $includeDelivery = !empty($data['include_delivery']);

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
        if ($intentPurchase && $includeDelivery) {
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
     * @param  array<int, array<string, mixed>>  $incomingCartItems
     * @return array<int, array<string, mixed>>
     */
    private function filterIncomingCartItemsForCurrentMessage(array $incomingCartItems, string $inputText): array
    {
        if ($incomingCartItems === []) {
            return [];
        }

        $mentionedColorIds = $this->extractMentionedColorIds($inputText);
        if ($mentionedColorIds === []) {
            return $incomingCartItems;
        }

        $filtered = array_values(array_filter($incomingCartItems, function (array $item) use ($mentionedColorIds): bool {
            $itemColorId = $this->nullableInt($item['color_id'] ?? null);
            if ($itemColorId !== null) {
                return in_array($itemColorId, $mentionedColorIds, true);
            }

            $itemColor = $this->cleanNullableString($item['color'] ?? null);
            if ($itemColor === null) {
                return false;
            }

            $resolvedItemColorId = $this->resolveColorId($itemColor, $itemColor);

            return $resolvedItemColorId !== null
                && in_array($resolvedItemColorId, $mentionedColorIds, true);
        }));

        return $filtered !== [] ? $filtered : $incomingCartItems;
    }

    /**
     * @param  array<int, array<string, mixed>>  $incomingCartItems
     * @param  array<int, array<string, mixed>>  $existingCartItems
     */
    private function shouldReplaceExistingCartItems(string $inputText, array $incomingCartItems, array $existingCartItems): bool
    {
        if ($incomingCartItems === [] || $existingCartItems === []) {
            return false;
        }

        $normalizedText = mb_strtolower(trim($inputText));
        if ($normalizedText === '') {
            return false;
        }

        if (preg_match('/\b(ще|додай|додайте|також|плюс)\b/ui', $normalizedText) === 1) {
            return false;
        }

        if (preg_match('/\b(давайте|беру|хочу|замовляю|оформляємо|потрібно)\b/ui', $normalizedText) !== 1) {
            return false;
        }

        $mentionedColorIds = $this->extractMentionedColorIds($inputText);
        if ($mentionedColorIds === []) {
            return count($incomingCartItems) > 1;
        }

        $existingMentionedItems = array_values(array_filter($existingCartItems, function (array $item) use ($mentionedColorIds): bool {
            $itemColorId = $this->nullableInt($item['color_id'] ?? null);
            if ($itemColorId !== null) {
                return in_array($itemColorId, $mentionedColorIds, true);
            }

            $itemColor = $this->cleanNullableString($item['color'] ?? null);
            if ($itemColor === null) {
                return false;
            }

            $resolvedItemColorId = $this->resolveColorId($itemColor, $itemColor);

            return $resolvedItemColorId !== null
                && in_array($resolvedItemColorId, $mentionedColorIds, true);
        }));

        return count($existingMentionedItems) !== count($incomingCartItems) || count($incomingCartItems) > 1;
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
     * @return array<int, int>
     */
    private function extractMentionedColorIds(string $text): array
    {
        $normalizedText = mb_strtolower($text);
        if ($normalizedText === '') {
            return [];
        }

        $wordParts = preg_split('/[^[:alpha:][:digit:]]+/u', $normalizedText) ?: [];
        $normalizedWords = array_values(array_filter(array_map(
            fn (string $word): string => $this->normalizeColorLexeme($word),
            $wordParts
        )));

        $mentionedColorIds = [];
        $colors = Color::query()
            ->select(['id', 'name'])
            ->orderByDesc(\DB::raw('CHAR_LENGTH(name)'))
            ->get();

        foreach ($colors as $color) {
            $colorName = mb_strtolower(trim((string) $color->name));
            if ($colorName === '') {
                continue;
            }

            if (mb_stripos($normalizedText, $colorName) !== false) {
                $mentionedColorIds[] = (int) $color->id;
                continue;
            }

            $colorStem = $this->normalizeColorLexeme($colorName);
            if ($colorStem === '') {
                continue;
            }

            foreach ($normalizedWords as $wordStem) {
                if ($wordStem === '') {
                    continue;
                }

                if (
                    str_starts_with($wordStem, $colorStem)
                    || str_starts_with($colorStem, $wordStem)
                ) {
                    $mentionedColorIds[] = (int) $color->id;
                    break;
                }
            }
        }

        return array_values(array_unique($mentionedColorIds));
    }

    private function normalizeColorLexeme(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        if ($normalized === '') {
            return '';
        }

        $normalized = preg_replace('/[^[:alpha:][:digit:]]+/u', '', $normalized) ?? '';
        if ($normalized === '') {
            return '';
        }

        $suffixes = [
            'ього', 'ьому', 'ими', 'ями', 'ого', 'ому', 'еві', 'ові', 'ими',
            'ий', 'ій', 'а', 'я', 'е', 'є', 'і', 'ї', 'у', 'ю', 'о',
        ];

        foreach ($suffixes as $suffix) {
            if (
                mb_strlen($normalized) > mb_strlen($suffix) + 2
                && str_ends_with($normalized, $suffix)
            ) {
                $normalized = mb_substr($normalized, 0, mb_strlen($normalized) - mb_strlen($suffix));
                break;
            }
        }

        return $normalized;
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

    private function sumNullableInts(?int $left, ?int $right): ?int
    {
        if ($left === null && $right === null) {
            return null;
        }

        return ($left ?? 0) + ($right ?? 0);
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

    private function resolveDebounceSeconds(): int
    {
        $seconds = (int) $this->settings()['reply_delay_seconds'];

        return min(25, max(8, $seconds));
    }

    private function isLatestInboundMessage(int $conversationId, int $messageId, array $sources = ['webhook']): bool
    {
        $latestInboundId = ChatMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('direction', 'inbound')
            ->whereIn('source', $sources)
            ->latest('id')
            ->value('id');

        return (int) $latestInboundId === $messageId;
    }

    private function hasOutboundAfterMessage(ChatMessage $message): bool
    {
        $sentAt = $message->sent_at ?? $message->created_at;

        return ChatMessage::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('direction', 'outbound')
            ->where(function ($query) use ($sentAt, $message): void {
                $query->where('sent_at', '>', $sentAt)
                    ->orWhere(function ($nested) use ($sentAt, $message): void {
                        $nested->where('sent_at', $sentAt)
                            ->where('id', '>', $message->id);
                    });
            })
            ->exists();
    }

    private function shouldSkipStaleReply(int $conversationId, int $messageId, array $sources = ['webhook']): bool
    {
        return !$this->isLatestInboundMessage($conversationId, $messageId, $sources);
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
