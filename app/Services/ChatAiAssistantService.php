<?php

namespace App\Services;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatAiResponseRule;
use App\Models\ChatAiTopic;
use App\Models\ChatStage;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class ChatAiAssistantService
{
    private const META_KEY = 'ai';
    private const SALES_STATES = [
        'idle',
        'intent',
        'product',
        'variant',
        'stock',
        'qty',
        'delivery',
        'payment',
        'contact',
        'confirm',
        'handoff',
    ];

    public function __construct(
        private readonly OpenAiResponsesService $openAiResponses,
        private readonly ChatService $chatService,
        private readonly MetaService $metaService,
        private readonly ChatAiSettingsService $chatAiSettings
    ) {
    }

    public function isFeatureEnabled(): bool
    {
        return (bool) $this->chatAiSettings->resolveRuntimeSettings()['enabled'];
    }

    public function isConfigured(): bool
    {
        return $this->openAiResponses->isConfigured();
    }

    public function queueInboundMessage(ChatConversation $conversation, ChatMessage $message): void
    {
        if (!$this->isFeatureEnabled()) {
            return;
        }

        $state = $this->getState($conversation);
        if (!$state['enabled']) {
            return;
        }

        $this->storeState($conversation, [
            'status' => $this->isConfigured() ? 'queued' : 'not_configured',
            'last_inbound_message_id' => $message->id,
            'last_error' => '',
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    public function setEnabled(ChatConversation $conversation, bool $enabled, ?User $user = null): ChatConversation
    {
        $state = $this->getState($conversation);

        $this->storeState($conversation, [
            'enabled' => $enabled,
            'status' => $enabled
                ? ($this->isFeatureEnabled()
                    ? ($this->isConfigured() ? 'idle' : 'not_configured')
                    : 'disabled')
                : 'paused',
            'handoff_required' => false,
            'handoff_reason' => '',
            'last_error' => '',
            'paused_at' => $enabled ? '' : now()->toDateTimeString(),
            'paused_by_user_id' => $enabled ? null : $user?->id,
            'updated_at' => now()->toDateTimeString(),
            'summary' => $state['summary'],
            'lead' => $state['lead'],
        ]);

        return $this->freshConversation($conversation);
    }

    public function takeOver(ChatConversation $conversation, User $user, string $reason = 'Менеджер забрав діалог у роботу.'): ChatConversation
    {
        $conversation->assigned_user_id = $user->id;
        $conversation->save();

        $state = $this->getState($conversation);

        $this->storeState($conversation, [
            'enabled' => false,
            'status' => 'manual',
            'handoff_required' => false,
            'handoff_reason' => $reason,
            'last_error' => '',
            'paused_at' => now()->toDateTimeString(),
            'paused_by_user_id' => $user->id,
            'updated_at' => now()->toDateTimeString(),
            'summary' => $state['summary'],
            'lead' => $state['lead'],
        ]);

        return $this->freshConversation($conversation);
    }

    public function registerOperatorReply(ChatConversation $conversation, User $user): ChatConversation
    {
        return $this->takeOver($conversation, $user, 'Менеджер відповів вручну, AI поставлено на паузу.');
    }

    /**
     * @return array<string, mixed>
     */
    public function getPublicState(ChatConversation $conversation): array
    {
        $state = $this->getState($conversation);

        return [
            'available' => $this->isConfigured(),
            'system_enabled' => $this->isFeatureEnabled(),
            'enabled' => (bool) $state['enabled'],
            'status' => (string) $state['status'],
            'summary' => (string) $state['summary'],
            'lead_status' => (string) $state['lead_status'],
            'lead' => $state['lead'],
            'handoff_required' => (bool) $state['handoff_required'],
            'handoff_reason' => (string) $state['handoff_reason'],
            'last_error' => (string) $state['last_error'],
            'last_ai_response_at' => $state['last_ai_response_at'] ?: null,
            'last_inbound_message_id' => $state['last_inbound_message_id'],
            'last_processed_message_id' => $state['last_processed_message_id'],
            'last_reply_message_id' => $state['last_reply_message_id'],
            'model' => $state['model'],
            'sales_state' => $state['sales_state'],
            'selected_topic_id' => $state['selected_topic_id'],
            'selected_product_id' => $state['selected_product_id'],
            'offered_models' => $state['offered_models'],
            'sales_slots' => $state['sales_slots'],
            'next_required_slot' => $state['next_required_slot'],
        ];
    }

    public function processInboundMessage(ChatConversation $conversation, ChatMessage $message): void
    {
        $conversation = $this->freshConversation($conversation);
        $message = $message->fresh(['attachments']);

        if (!$conversation || !$message) {
            return;
        }

        if (!$this->isFeatureEnabled()) {
            return;
        }

        if (!$this->isConfigured()) {
            $this->storeState($conversation, [
                'status' => 'not_configured',
                'last_inbound_message_id' => $message->id,
                'last_error' => 'OPENAI_API_KEY не налаштований.',
                'updated_at' => now()->toDateTimeString(),
            ]);

            return;
        }

        if (!$this->canProcessMessage($conversation, $message)) {
            return;
        }

        $runtimeSettings = $this->chatAiSettings->resolveRuntimeSettings();
        $currentState = $this->getState($conversation);
        $knowledgeContext = $this->resolveKnowledgeContext($message, $currentState);
        $salesSlots = $this->resolveSalesSlots($conversation, $message, $knowledgeContext, $currentState);
        $knowledgeContext['sales_slots'] = $salesSlots;
        $knowledgeContext['next_required_slot'] = $this->determineNextRequiredSlot($knowledgeContext, $salesSlots, $message);
        $knowledgeContext['order_ready'] = $this->isOrderReady($knowledgeContext, $salesSlots);

        $this->storeState($conversation, [
            'enabled' => $currentState['enabled'],
            'status' => 'processing',
            'last_inbound_message_id' => $message->id,
            'last_error' => '',
            'updated_at' => now()->toDateTimeString(),
            'summary' => $currentState['summary'],
            'lead' => $currentState['lead'],
            'sales_state' => $currentState['sales_state'],
            'selected_topic_id' => $currentState['selected_topic_id'],
            'selected_product_id' => $currentState['selected_product_id'],
            'offered_models' => $currentState['offered_models'],
            'sales_slots' => $currentState['sales_slots'],
            'next_required_slot' => $currentState['next_required_slot'],
        ]);

        try {
            $decision = $this->openAiResponses->createStructuredResponse(
                $this->buildInstructions($runtimeSettings, $knowledgeContext),
                $this->buildInput($conversation, $message, $runtimeSettings, $knowledgeContext),
                $this->decisionSchema(),
                'chat_first_line_triage',
                (string) $runtimeSettings['model']
            );
        } catch (\Throwable $e) {
            Log::warning('Chat AI decision failed', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            $this->storeState($conversation, [
                'status' => 'error',
                'last_inbound_message_id' => $message->id,
                'last_processed_message_id' => $message->id,
                'last_error' => $e->getMessage(),
                'updated_at' => now()->toDateTimeString(),
            ]);

            return;
        }

        $conversation = $this->freshConversation($conversation);
        if (!$this->canProcessMessage($conversation, $message)) {
            return;
        }

        $salesSlots = $this->mergeSalesSlots(
            $salesSlots,
            $decision['sales_slots'] ?? [],
            $knowledgeContext,
            $conversation
        );
        $knowledgeContext['sales_slots'] = $salesSlots;
        $knowledgeContext['next_required_slot'] = $this->determineNextRequiredSlot($knowledgeContext, $salesSlots, $message);
        $knowledgeContext['order_ready'] = $this->isOrderReady($knowledgeContext, $salesSlots);

        $rawReplyText = (string) ($decision['reply_text'] ?? '');
        $inlineAttachmentUrls = $this->extractInlineAttachmentUrls($rawReplyText, $knowledgeContext);
        $replyText = $this->sanitizeReplyText($rawReplyText);
        $replyText = $this->enforceSalesReplyText($replyText, $knowledgeContext);
        $replyText = $this->fallbackReplyText($replyText, $knowledgeContext);
        $handoffRequired = (bool) ($decision['handoff_required'] ?? false);
        if ((bool) ($knowledgeContext['order_ready'] ?? false)) {
            $handoffRequired = true;
        }
        $mustReply = (bool) ($knowledgeContext['requires_model_choice'] ?? false)
            || (bool) ($knowledgeContext['requires_size_chart'] ?? false)
            || !empty($knowledgeContext['next_required_slot'])
            || (bool) ($knowledgeContext['order_ready'] ?? false);
        $shouldReply = ((bool) ($decision['should_reply'] ?? false) || $mustReply) && $replyText !== '';
        $replyAttachments = $this->resolveReplyAttachments(
            array_values(array_unique(array_merge(
                array_values(array_filter((array) ($decision['attachment_urls'] ?? []), 'is_string')),
                $inlineAttachmentUrls
            ))),
            $knowledgeContext,
            $message
        );
        $summary = $this->normalizeSummary($decision);
        $lead = $this->mergeLeadWithSalesSlots(
            $this->normalizeLeadPayload($decision['collected_data'] ?? []),
            $salesSlots,
            $knowledgeContext
        );
        $leadStatus = $this->normalizeLeadStatus((string) ($decision['lead_status'] ?? 'new'));
        if ((bool) ($knowledgeContext['order_ready'] ?? false)) {
            $leadStatus = 'handoff';
        }
        $handoffReason = trim((string) ($decision['handoff_reason'] ?? ''));
        if ($handoffRequired && $handoffReason === '') {
            $handoffReason = 'AI зібрав основні дані замовлення, потрібне оформлення менеджером.';
        }
        $sentMessage = null;

        try {
            if ($shouldReply) {
                $sentMessage = $this->sendAiReply(
                    $conversation,
                    $replyText,
                    $handoffRequired,
                    $leadStatus,
                    (string) $runtimeSettings['model'],
                    $replyAttachments
                );
                $conversation = $this->freshConversation($conversation);
            }
        } catch (\Throwable $e) {
            Log::warning('Chat AI send failed', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            $handoffRequired = true;
            $handoffReason = $handoffReason !== '' ? $handoffReason : 'Не вдалося відправити AI-відповідь, потрібен менеджер.';
        }

        $status = $handoffRequired
            ? 'handoff'
            : ($sentMessage ? 'replied' : 'idle');
        $salesStatePatch = $this->buildSalesStatePatch($currentState, $knowledgeContext, $handoffRequired);

        $this->storeState($conversation, [
            'status' => $status,
            'last_inbound_message_id' => $message->id,
            'last_processed_message_id' => $message->id,
            'last_reply_message_id' => $sentMessage?->id,
            'last_ai_response_at' => $sentMessage ? now()->toDateTimeString() : ($currentState['last_ai_response_at'] ?? ''),
            'handoff_required' => $handoffRequired,
            'handoff_reason' => $handoffRequired ? $handoffReason : '',
            'summary' => $summary,
            'lead_status' => $leadStatus,
            'lead' => $lead,
            'last_error' => '',
            'model' => (string) $runtimeSettings['model'],
            'updated_at' => now()->toDateTimeString(),
            ...$salesStatePatch,
        ]);

        if ($handoffRequired) {
            $this->moveConversationToStage($conversation, 'new');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getState(ChatConversation $conversation): array
    {
        $stored = data_get($conversation->meta, self::META_KEY, []);
        $lead = is_array($stored['lead'] ?? null) ? $stored['lead'] : [];

        return [
            'enabled' => array_key_exists('enabled', $stored) ? (bool) $stored['enabled'] : true,
            'status' => (string) ($stored['status'] ?? ($this->isConfigured() ? 'idle' : 'not_configured')),
            'summary' => (string) ($stored['summary'] ?? ''),
            'lead_status' => (string) ($stored['lead_status'] ?? 'new'),
            'lead' => [
                'customer_name' => (string) ($lead['customer_name'] ?? ''),
                'phone' => (string) ($lead['phone'] ?? ''),
                'product_interest' => (string) ($lead['product_interest'] ?? ''),
                'budget' => (string) ($lead['budget'] ?? ''),
                'timeline' => (string) ($lead['timeline'] ?? ''),
                'city' => (string) ($lead['city'] ?? ''),
                'notes' => (string) ($lead['notes'] ?? ''),
            ],
            'handoff_required' => (bool) ($stored['handoff_required'] ?? false),
            'handoff_reason' => (string) ($stored['handoff_reason'] ?? ''),
            'last_error' => (string) ($stored['last_error'] ?? ''),
            'last_ai_response_at' => (string) ($stored['last_ai_response_at'] ?? ''),
            'last_inbound_message_id' => isset($stored['last_inbound_message_id']) ? (int) $stored['last_inbound_message_id'] : null,
            'last_processed_message_id' => isset($stored['last_processed_message_id']) ? (int) $stored['last_processed_message_id'] : null,
            'last_reply_message_id' => isset($stored['last_reply_message_id']) ? (int) $stored['last_reply_message_id'] : null,
            'paused_at' => (string) ($stored['paused_at'] ?? ''),
            'paused_by_user_id' => isset($stored['paused_by_user_id']) ? (int) $stored['paused_by_user_id'] : null,
            'model' => (string) ($stored['model'] ?? config('services.openai.model', 'gpt-4.1-mini')),
            'sales_state' => $this->normalizeSalesState((string) ($stored['sales_state'] ?? 'idle')),
            'selected_topic_id' => isset($stored['selected_topic_id']) ? (int) $stored['selected_topic_id'] : null,
            'selected_product_id' => isset($stored['selected_product_id']) ? (int) $stored['selected_product_id'] : null,
            'offered_models' => $this->normalizeOfferedModels($stored['offered_models'] ?? []),
            'sales_slots' => $this->normalizeSalesSlots($stored['sales_slots'] ?? []),
            'next_required_slot' => $this->normalizeSalesSlotKey($stored['next_required_slot'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function storeState(ChatConversation $conversation, array $state): void
    {
        $meta = $conversation->meta ?: [];
        $current = $this->getState($conversation);

        $next = array_merge($current, $state);
        $next['lead'] = $this->normalizeLeadPayload($next['lead'] ?? []);
        $next['sales_state'] = $this->normalizeSalesState((string) ($next['sales_state'] ?? 'idle'));
        $next['selected_topic_id'] = isset($next['selected_topic_id']) ? (int) $next['selected_topic_id'] : null;
        $next['selected_product_id'] = isset($next['selected_product_id']) ? (int) $next['selected_product_id'] : null;
        $next['offered_models'] = $this->normalizeOfferedModels($next['offered_models'] ?? []);
        $next['sales_slots'] = $this->normalizeSalesSlots($next['sales_slots'] ?? []);
        $next['next_required_slot'] = $this->normalizeSalesSlotKey($next['next_required_slot'] ?? null);
        $meta[self::META_KEY] = $next;

        $conversation->meta = $meta;
        $conversation->save();
    }

    private function canProcessMessage(ChatConversation $conversation, ChatMessage $message): bool
    {
        if ($message->direction !== 'inbound') {
            return false;
        }

        if ($conversation->status === 'archived') {
            return false;
        }

        $state = $this->getState($conversation);
        if (!$state['enabled']) {
            return false;
        }

        if (($state['last_processed_message_id'] ?? 0) >= $message->id) {
            return false;
        }

        if ($this->hasNewerInboundMessage($conversation, $message)) {
            return false;
        }

        if ($this->hasOperatorReplyAfter($conversation, $message)) {
            return false;
        }

        return true;
    }

    private function hasNewerInboundMessage(ChatConversation $conversation, ChatMessage $message): bool
    {
        return ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->where('id', '>', $message->id)
            ->exists();
    }

    private function hasOperatorReplyAfter(ChatConversation $conversation, ChatMessage $message): bool
    {
        return ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->where('source', 'operator')
            ->where('id', '>', $message->id)
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    private function buildTranscript(ChatConversation $conversation, int $limit): array
    {
        $messages = ChatMessage::query()
            ->with('attachments')
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->sortBy('id')
            ->values();

        return $messages->map(function (ChatMessage $message) {
            $speaker = match ($message->direction) {
                'inbound' => 'Клієнт',
                default => ($message->source === 'system' ? 'AI' : 'Менеджер'),
            };

            $parts = [];
            $text = trim((string) $message->text);
            if ($text !== '') {
                $parts[] = $text;
            }

            if ($message->attachments->isNotEmpty()) {
                $attachmentTypes = $message->attachments
                    ->pluck('attachment_type')
                    ->filter()
                    ->unique()
                    ->implode(', ');
                $parts[] = '[вкладення: ' . $attachmentTypes . ']';
            }

            if ($parts === []) {
                $parts[] = '[порожнє повідомлення]';
            }

            return $speaker . ': ' . implode(' ', $parts);
        })->all();
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $knowledgeContext
     */
    private function buildInstructions(array $settings, array $knowledgeContext): string
    {
        $instructions = [
            'Ти ' . $settings['assistant_name'] . ', AI-асистент першої лінії для CRM інтернет-магазину.',
            'Спілкуйся тільки українською мовою.',
            'Ти відповідаєш першим, збираєш дані та передаєш менеджеру складні або продажні кейси.',
            'Не вигадуй ціну, наявність, строки доставки, знижки або умови оплати, якщо цього немає в контексті.',
            'Якщо клієнт хоче точну ціну, знижку, оплату, рекламацію, живого менеджера або запит нестандартний, став handoff_required=true.',
            'Якщо даних бракує, став коротке наступне релевантне запитання.',
            'Відповідь клієнту має бути коротка: 1-3 речення, максимум 2 запитання, без води.',
            'Не використовуй російську мову.',
            'Якщо потрібно передати менеджеру, можеш коротко написати, що передаєш запит менеджеру.',
            'Поверни тільки JSON за заданою схемою.',
            'Стиль відповіді: ' . $settings['reply_style'],
        ];

        if ($settings['qualification_fields'] !== []) {
            $instructions[] = 'Під час кваліфікації збирай по можливості: ' . implode(', ', $settings['qualification_fields']) . '.';
        }

        if ($settings['company_context'] !== '') {
            $instructions[] = 'Контекст бізнесу: ' . $this->limitText((string) $settings['company_context'], 2000);
        }

        if ($settings['handoff_rules'] !== '') {
            $instructions[] = 'Окремі правила передачі менеджеру: ' . $this->limitText((string) $settings['handoff_rules'], 2000);
        }

        if ($settings['knowledge_base'] !== '') {
            $instructions[] = 'База знань: ' . $this->limitText((string) $settings['knowledge_base'], 4000);
        }

        if (!empty($knowledgeContext['rules'])) {
            $instructions[] = 'Активні сценарії відповіді (обовʼязково дотримуйся):';
            foreach ($knowledgeContext['rules'] as $rule) {
                $instructions[] = '- ' . $rule['code'] . ': ' . $this->limitText((string) $rule['instruction'], 260);
            }
        }

        if (!empty($knowledgeContext['selected_topic'])) {
            $topic = $knowledgeContext['selected_topic'];
            $instructions[] = 'Поточна тема запиту: ' . $topic['name'] . '.';
            if (!empty($topic['instruction'])) {
                $instructions[] = 'Інструкція теми: ' . $this->limitText((string) $topic['instruction'], 900);
            }
            $instructions[] = 'Не змішуй з іншими темами та не вигадуй дані поза контекстом.';
        } else {
            $instructions[] = 'Якщо тема не визначена, спочатку уточни модель/тип товару одним коротким питанням.';
        }

        $instructions[] = 'Для фото використовуй тільки URL з релевантного медіа-контексту.';
        $instructions[] = 'Для ціни та розмірів використовуй тільки релевантні товари з контексту.';
        $instructions[] = 'Коли клієнт просить показати/надіслати фото, заповнюй attachment_urls релевантними URL тільки з цього контексту.';
        $instructions[] = 'Якщо фото не запитували або URL немає в контексті, поверни attachment_urls як порожній масив.';
        $instructions[] = 'Не вставляй URL, markdown-посилання або текст виду [фото](url) у reply_text. Усі зображення передавай тільки через attachment_urls.';

        if ((bool) ($knowledgeContext['requires_model_choice'] ?? false)) {
            $instructions[] = 'Зараз модель не визначена. Потрібно коротко уточнити, яку саме модель клієнт має на увазі.';
            $instructions[] = 'Сформуй відповідь у форматі: привітання + прохання вибрати модель (номер) + прохання написати розмір.';
            $instructions[] = 'Для цього кроку handoff_required має бути false.';
            $instructions[] = 'Не додавай attachment_urls, якщо клієнт окремо не просив показати фото.';
        }

        if ((bool) ($knowledgeContext['requires_size_chart'] ?? false)) {
            $instructions[] = 'Клієнт питає про розмір для вже обраної моделі.';
            $instructions[] = 'Коротко поясни, як вибрати розмір, і додай у attachment_urls релевантну size_chart.';
        }

        $instructions[] = 'Працюй як slot-асистент замовлення. Не став більше одного нового питання в одній відповіді.';
        if (!empty($knowledgeContext['sales_slots'])) {
            $instructions[] = 'Поточні sales slots: ' . $this->formatSalesSlotsForPrompt($knowledgeContext['sales_slots']);
        }
        if (!empty($knowledgeContext['next_required_slot'])) {
            $instructions[] = 'Бракує слота: ' . $this->humanSalesSlotLabel((string) $knowledgeContext['next_required_slot']) . '.';
            $instructions[] = 'Постав тільки одне коротке питання саме про цей слот.';
        }
        if ((bool) ($knowledgeContext['order_ready'] ?? false)) {
            $instructions[] = 'Усі основні слоти замовлення зібрані. Сформуй коротке підтвердження замовлення і постав handoff_required=true.';
        }

        return implode("\n", $instructions);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $knowledgeContext
     */
    private function buildInput(
        ChatConversation $conversation,
        ChatMessage $message,
        array $settings,
        array $knowledgeContext
    ): string {
        $customer = $conversation->customer;
        $contact = $conversation->contact;
        $state = $this->getState($conversation);
        $transcript = $this->buildTranscript($conversation, (int) $settings['max_messages']);

        $input = [
            'Канал: ' . ($contact?->platform === 'instagram' ? 'Instagram' : 'Messenger'),
            'Клієнт: ' . $this->chatService->resolveDisplayName($contact, $customer),
            'Телефон у CRM: ' . ($customer?->phone ?: 'немає'),
            'Email у CRM: ' . ($customer?->email ?: 'немає'),
            'Поточний етап: ' . ($conversation->stage?->name ?: 'Без етапу'),
            'Поточний AI summary: ' . ($state['summary'] ?: 'немає'),
            'Поточний sales state: ' . ((string) ($state['sales_state'] ?? 'idle')),
            'Намір клієнта: ' . ((string) ($knowledgeContext['intent'] ?? 'unknown')),
            'Поточні sales slots: ' . $this->formatSalesSlotsForPrompt($knowledgeContext['sales_slots'] ?? []),
            'Наступний слот: ' . ($knowledgeContext['next_required_slot']
                ? $this->humanSalesSlotLabel((string) $knowledgeContext['next_required_slot'])
                : 'немає'),
            'Замовлення зібрано: ' . ((bool) ($knowledgeContext['order_ready'] ?? false) ? 'так' : 'ні'),
            'Останнє вхідне повідомлення: ' . $this->formatLatestInbound($message),
        ];

        if (!empty($knowledgeContext['selected_topic'])) {
            $topic = $knowledgeContext['selected_topic'];
            $input[] = 'Знайдена тема: ' . $topic['name'];

            if (!empty($knowledgeContext['matched_positive'])) {
                $input[] = 'Збіги за ключовими словами: ' . implode(', ', $knowledgeContext['matched_positive']);
            }

            if (!empty($knowledgeContext['products'])) {
                $input[] = 'Релевантні товари теми:';
                foreach ($knowledgeContext['products'] as $idx => $product) {
                    $parts = [$product['title']];

                    if ($product['price'] !== null) {
                        $parts[] = 'ціна ' . $this->formatPrice((float) $product['price']) . ' грн';
                    }

                    if ($product['sizes'] !== []) {
                        $parts[] = 'розміри ' . implode(', ', $product['sizes']);
                    }

                    if ($product['sku'] !== '') {
                        $parts[] = 'SKU ' . $product['sku'];
                    }

                    if ($product['photo_url'] !== '') {
                        $parts[] = 'фото ' . $product['photo_url'];
                    }

                    $input[] = ($idx + 1) . '. ' . implode('; ', $parts);
                }
            }

            if (!empty($knowledgeContext['media'])) {
                $input[] = 'Релевантні медіа теми (можна давати URL клієнту):';
                foreach ($knowledgeContext['media'] as $idx => $media) {
                    $input[] = ($idx + 1) . '. '
                        . $media['label'] . ' | '
                        . $media['media_type'] . ' | '
                        . $media['url'];
                }
            }

            if (!empty($knowledgeContext['selected_product'])) {
                $selectedProduct = $knowledgeContext['selected_product'];
                $selectedProductParts = [
                    (string) ($selectedProduct['title'] ?? ''),
                ];

                if (!empty($selectedProduct['sku'])) {
                    $selectedProductParts[] = 'SKU ' . $selectedProduct['sku'];
                }

                if (array_key_exists('price', $selectedProduct) && $selectedProduct['price'] !== null) {
                    $selectedProductParts[] = 'ціна ' . $this->formatPrice((float) $selectedProduct['price']) . ' грн';
                }

                if (!empty($selectedProduct['sizes']) && is_array($selectedProduct['sizes'])) {
                    $selectedProductParts[] = 'розміри ' . implode(', ', $selectedProduct['sizes']);
                }

                $input[] = 'Поточна обрана модель: ' . implode('; ', array_filter($selectedProductParts));
            }

            if (!empty($knowledgeContext['model_choices'])) {
                $input[] = 'Список моделей для вибору (проси номер моделі):';
                foreach ($knowledgeContext['model_choices'] as $modelChoice) {
                    $parts = [
                        '#' . (int) ($modelChoice['number'] ?? 0),
                        (string) ($modelChoice['title'] ?? ''),
                    ];

                    if (!empty($modelChoice['sku'])) {
                        $parts[] = 'SKU ' . $modelChoice['sku'];
                    }

                    if (array_key_exists('price', $modelChoice) && $modelChoice['price'] !== null) {
                        $parts[] = 'ціна ' . $this->formatPrice((float) $modelChoice['price']) . ' грн';
                    }

                    if (!empty($modelChoice['sizes']) && is_array($modelChoice['sizes'])) {
                        $parts[] = 'розміри ' . implode(', ', $modelChoice['sizes']);
                    }

                    $input[] = implode('; ', array_filter($parts));
                }
            }

            if (!empty($knowledgeContext['showcase_media'])) {
                $input[] = 'showcase_media (доступні колажі/вітрина):';
                foreach ($knowledgeContext['showcase_media'] as $idx => $media) {
                    $input[] = ($idx + 1) . '. '
                        . ((string) ($media['label'] ?? ''))
                        . ' | ' . ((string) ($media['media_type'] ?? ''))
                        . ' | ' . ((string) ($media['url'] ?? ''));
                }
            }

            if (!empty($knowledgeContext['size_chart_media'])) {
                $input[] = 'size_chart_media (доступні розмірні сітки):';
                foreach ($knowledgeContext['size_chart_media'] as $idx => $media) {
                    $input[] = ($idx + 1) . '. '
                        . ((string) ($media['label'] ?? ''))
                        . ' | ' . ((string) ($media['media_type'] ?? ''))
                        . ' | ' . ((string) ($media['url'] ?? ''));
                }
            }
        } else {
            $input[] = 'Тема не визначена. Якщо запит нечіткий, уточни тип товару.';
        }

        if ((bool) ($knowledgeContext['requires_model_choice'] ?? false)) {
            $input[] = 'Системна задача: тема/модель не визначена, потрібно уточнити модель і попросити номер.';
        }

        if ((bool) ($knowledgeContext['requires_size_chart'] ?? false)) {
            $input[] = 'Системна задача: поясни підбір розміру та додай size_chart у attachment_urls.';
        }

        $input[] = 'Останні повідомлення:';
        $input = [...$input, ...$transcript];

        return implode("\n", $input);
    }

    /**
     * @param  mixed  $attachmentUrls
     * @param  array<string, mixed>  $knowledgeContext
     * @return array<int, array{meta_payload: array<string, string>, stored_attachment: array<string, mixed>}>
     */
    private function resolveReplyAttachments(
        mixed $attachmentUrls,
        array $knowledgeContext,
        ChatMessage $message
    ): array {
        $allowedMap = $this->allowedAttachmentMap($knowledgeContext);
        if ($allowedMap === []) {
            return [];
        }

        $photoRequested = $this->isPhotoIntent($message);
        $forceSizeChart = (bool) ($knowledgeContext['requires_size_chart'] ?? false)
            && !empty($knowledgeContext['size_chart_media']);

        if (!$photoRequested && !$forceSizeChart) {
            return [];
        }

        $maxAttachments = $forceSizeChart
            ? max(1, count((array) ($knowledgeContext['size_chart_media'] ?? [])))
            : max(1, count((array) $attachmentUrls));
        $selected = [];
        $appendUrl = function (string $rawUrl) use (&$selected, $allowedMap, $maxAttachments): void {
            if (count($selected) >= $maxAttachments) {
                return;
            }

            $normalizedUrl = $this->normalizeAttachmentUrl($rawUrl);
            if ($normalizedUrl === '' || !isset($allowedMap[$normalizedUrl])) {
                return;
            }

            $selected[$normalizedUrl] = $normalizedUrl;
        };

        foreach ((array) $attachmentUrls as $rawUrl) {
            if (!is_string($rawUrl)) {
                continue;
            }

            $appendUrl($rawUrl);
        }

        if ($forceSizeChart) {
            foreach ((array) ($knowledgeContext['size_chart_media'] ?? []) as $mediaItem) {
                $appendUrl((string) ($mediaItem['url'] ?? ''));
            }
        }

        if ($photoRequested) {
            foreach ((array) ($knowledgeContext['showcase_media'] ?? []) as $mediaItem) {
                $appendUrl((string) ($mediaItem['url'] ?? ''));
            }
        }

        if ($selected === [] || (!$forceSizeChart && count($selected) < $maxAttachments)) {
            foreach ($this->fallbackAttachmentCandidates($message, $knowledgeContext) as $candidateUrl) {
                $appendUrl($candidateUrl);
            }
        }

        return array_map(
            fn (string $url) => $this->buildAttachmentPayload($url),
            array_values($selected)
        );
    }

    /**
     * @param  array<string, mixed>  $knowledgeContext
     * @return array<string, string>
     */
    private function allowedAttachmentMap(array $knowledgeContext): array
    {
        $map = [];

        foreach ((array) ($knowledgeContext['media'] ?? []) as $media) {
            $url = $this->normalizeAttachmentUrl((string) ($media['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $map[$url] = $url;
        }

        foreach ((array) ($knowledgeContext['showcase_media'] ?? []) as $media) {
            $url = $this->normalizeAttachmentUrl((string) ($media['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $map[$url] = $url;
        }

        foreach ((array) ($knowledgeContext['size_chart_media'] ?? []) as $media) {
            $url = $this->normalizeAttachmentUrl((string) ($media['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $map[$url] = $url;
        }

        foreach ((array) ($knowledgeContext['products'] ?? []) as $product) {
            $url = $this->normalizeAttachmentUrl((string) ($product['photo_url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $map[$url] = $url;
        }

        foreach ((array) ($knowledgeContext['model_choices'] ?? []) as $modelChoice) {
            foreach (['photo_url', 'collage_url'] as $field) {
                $url = $this->normalizeAttachmentUrl((string) ($modelChoice[$field] ?? ''));
                if ($url === '') {
                    continue;
                }
                $map[$url] = $url;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $knowledgeContext
     * @return array<int, string>
     */
    private function fallbackAttachmentCandidates(ChatMessage $message, array $knowledgeContext): array
    {
        $query = $this->normalizeForMatch((string) $message->text);
        $tokens = array_values(array_filter(
            preg_split('/[\s,.;:!?()"\'«»\-\/]+/u', $query) ?: [],
            fn (string $token) => mb_strlen($token) >= 4
        ));

        $scored = [];
        foreach ((array) ($knowledgeContext['products'] ?? []) as $index => $product) {
            $url = trim((string) ($product['photo_url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $score = 1;
            $title = $this->normalizeForMatch((string) ($product['title'] ?? ''));
            $sku = $this->normalizeForMatch((string) ($product['sku'] ?? ''));

            foreach ($tokens as $token) {
                if ($title !== '' && Str::contains($title, $token)) {
                    $score += 5;
                }
                if ($sku !== '' && Str::contains($sku, $token)) {
                    $score += 8;
                }
            }

            $scored[] = [
                'url' => $url,
                'score' => $score,
                'order' => (int) $index,
            ];
        }

        foreach ((array) ($knowledgeContext['media'] ?? []) as $index => $media) {
            $url = trim((string) ($media['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $score = 1;
            $label = $this->normalizeForMatch((string) ($media['label'] ?? ''));
            $type = $this->normalizeForMatch((string) ($media['media_type'] ?? ''));

            foreach ($tokens as $token) {
                if ($label !== '' && Str::contains($label, $token)) {
                    $score += 4;
                }
                if ($type !== '' && Str::contains($type, $token)) {
                    $score += 2;
                }
            }

            $scored[] = [
                'url' => $url,
                'score' => $score,
                'order' => 1000 + (int) $index,
            ];
        }

        usort($scored, function (array $left, array $right) {
            return [$right['score'], $left['order']] <=> [$left['score'], $right['order']];
        });

        $candidates = [];
        foreach ($scored as $item) {
            $normalizedUrl = $this->normalizeAttachmentUrl((string) ($item['url'] ?? ''));
            if ($normalizedUrl === '' || isset($candidates[$normalizedUrl])) {
                continue;
            }
            $candidates[$normalizedUrl] = $normalizedUrl;
        }

        return array_values($candidates);
    }

    private function normalizeAttachmentUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (!str_starts_with($url, 'http')) {
            return url(ltrim($url, '/'));
        }

        return $url;
    }

    private function isPhotoIntent(ChatMessage $message): bool
    {
        $source = $this->normalizeForMatch((string) $message->text);
        if ($source === '') {
            return false;
        }

        $needles = [
            'фото',
            'показ',
            'побач',
            'картин',
            'зображ',
            'колаж',
            'палітр',
            'скинь',
            'надішл',
        ];

        foreach ($needles as $needle) {
            if (Str::contains($source, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{meta_payload: array<string, string>, stored_attachment: array<string, mixed>}
     */
    private function buildAttachmentPayload(string $url): array
    {
        $attachmentType = $this->inferAttachmentTypeByUrl($url);

        return [
            'meta_payload' => [
                'type' => $attachmentType,
                'url' => $url,
            ],
            'stored_attachment' => [
                'type' => $attachmentType,
                'url' => $url,
                'meta' => [
                    'source' => 'ai_knowledge',
                ],
            ],
        ];
    }

    private function inferAttachmentTypeByUrl(string $url): string
    {
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?? $url);
        if (preg_match('/\.(mp4|mov|webm)$/i', $path)) {
            return 'video';
        }
        if (preg_match('/\.(mp3|wav|ogg)$/i', $path)) {
            return 'audio';
        }
        if (preg_match('/\.(pdf|doc|docx|xls|xlsx)$/i', $path)) {
            return 'file';
        }

        return 'image';
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveKnowledgeContext(ChatMessage $message, array $state): array
    {
        $rules = ChatAiResponseRule::query()
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get(['code', 'title', 'instruction'])
            ->map(fn (ChatAiResponseRule $rule) => [
                'code' => (string) $rule->code,
                'title' => (string) $rule->title,
                'instruction' => (string) $rule->instruction,
            ])
            ->values()
            ->all();

        $topics = ChatAiTopic::query()
            ->where('is_active', true)
            ->with([
                'keywords' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderByDesc('weight')
                    ->select(['id', 'topic_id', 'phrase', 'match_type', 'weight']),
                'topicProducts' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->with([
                        'product' => fn ($productQuery) => $productQuery
                            ->where('is_active', true)
                            ->with([
                                'variants' => fn ($variantQuery) => $variantQuery
                                    ->where('is_active', true)
                                    ->orderBy('size')
                                    ->select(['id', 'product_id', 'size', 'stock_qty', 'is_active']),
                            ])
                            ->select(['id', 'title', 'sku', 'sale_price', 'is_active', 'main_photo_path']),
                    ])
                    ->select(['id', 'topic_id', 'product_id', 'sort_order', 'is_active']),
                'mediaItems' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->with('savedFile:id,filename,url,type')
                    ->select(['id', 'topic_id', 'saved_file_id', 'label', 'media_type', 'url', 'sort_order', 'is_active']),
            ])
            ->orderBy('priority')
            ->orderBy('name')
            ->get(['id', 'name', 'instruction', 'priority', 'is_active']);

        $matchSource = $this->normalizeForMatch($this->extractMessageForMatching($message));
        $intent = $this->detectSalesIntent($matchSource);

        if ($topics->isEmpty()) {
            return [
                'intent' => $intent,
                'selected_topic' => null,
                'selected_product' => null,
                'matched_positive' => [],
                'products' => [],
                'media' => [],
                'model_choices' => [],
                'showcase_media' => [],
                'size_chart_media' => [],
                'requires_model_choice' => false,
                'requires_size_chart' => false,
                'rules' => $rules,
            ];
        }

        $scored = $topics->map(function (ChatAiTopic $topic) use ($matchSource) {
            $score = 0;
            $positive = [];

            foreach ($topic->keywords as $keyword) {
                $phrase = $this->normalizeForMatch((string) $keyword->phrase);
                if ($phrase === '' || !Str::contains($matchSource, $phrase)) {
                    continue;
                }

                $weight = max(1, (int) $keyword->weight);
                if ($keyword->match_type === 'negative') {
                    $score -= ($weight * 2);
                    continue;
                }

                $score += $weight;
                $positive[] = (string) $keyword->phrase;
            }

            $topicName = $this->normalizeForMatch((string) $topic->name);
            if ($topicName !== '' && Str::contains($matchSource, $topicName)) {
                $score += 25;
            }

            return [
                'topic' => $topic,
                'score' => $score,
                'positive' => array_values(array_unique($positive)),
            ];
        })->sortByDesc('score')->values();

        $bestPositive = $scored->first(fn (array $row) => $row['score'] > 0);
        $stateTopic = null;
        if (!empty($state['selected_topic_id'])) {
            $stateTopic = $topics->firstWhere('id', (int) $state['selected_topic_id']);
        }

        $selectedFromOffer = $this->resolveOfferedModelSelection($matchSource, (array) ($state['offered_models'] ?? []));
        $selectedTopic = null;

        if ($selectedFromOffer !== null && !empty($selectedFromOffer['topic_id'])) {
            $selectedTopic = $topics->firstWhere('id', (int) $selectedFromOffer['topic_id']);
        }

        if ($selectedTopic === null && $bestPositive !== null) {
            $bestTopic = $bestPositive['topic'];
            if (
                $stateTopic === null
                || (int) $bestPositive['score'] >= 6
                || (int) $bestTopic->id === (int) $stateTopic->id
            ) {
                $selectedTopic = $bestTopic;
            }
        }

        if ($selectedTopic === null && $stateTopic !== null) {
            $selectedTopic = $stateTopic;
        }

        if ($selectedTopic === null && $bestPositive !== null) {
            $selectedTopic = $bestPositive['topic'];
        }

        if ($selectedTopic === null && $topics->count() === 1) {
            $selectedTopic = $topics->first();
        }

        if ($selectedTopic === null && $intent !== 'unknown') {
            $selectedTopic = $topics->first();
        }

        if ($selectedTopic === null) {
            return [
                'intent' => $intent,
                'selected_topic' => null,
                'selected_product' => null,
                'matched_positive' => [],
                'products' => [],
                'media' => [],
                'model_choices' => [],
                'showcase_media' => [],
                'size_chart_media' => [],
                'requires_model_choice' => false,
                'requires_size_chart' => false,
                'rules' => $rules,
            ];
        }

        $selectedRow = $scored->first(fn (array $row) => (int) $row['topic']->id === (int) $selectedTopic->id);
        $matchedPositive = is_array($selectedRow['positive'] ?? null) ? $selectedRow['positive'] : [];
        $products = $this->topicProductsPayload($selectedTopic);
        $media = $this->topicMediaPayload($selectedTopic);
        $selectedTopicPayload = [
            'id' => (int) $selectedTopic->id,
            'name' => (string) $selectedTopic->name,
            'instruction' => (string) ($selectedTopic->instruction ?? ''),
        ];

        $modelChoices = $this->buildModelChoices($products, $selectedTopicPayload);
        $selectedProduct = $this->resolveSelectedProduct($products, $matchSource, $selectedFromOffer, $state);
        $showcaseMedia = $this->resolveShowcaseMedia($media, $products);
        $sizeChartMedia = $this->resolveSizeChartMedia($media, $selectedProduct);

        $requiresModelChoice = $selectedProduct === null
            && $this->intentNeedsModelChoice($intent)
            && count($modelChoices) > 1;

        if ($requiresModelChoice) {
            $selectedProduct = null;
        }

        $requiresSizeChart = $selectedProduct !== null
            && $sizeChartMedia !== []
            && (
                $intent === 'size'
                || $this->looksLikeSizeValue($matchSource)
            );

        return [
            'intent' => $intent,
            'selected_topic' => $selectedTopicPayload,
            'selected_product' => $selectedProduct,
            'matched_positive' => array_slice($matchedPositive, 0, 6),
            'products' => $products,
            'media' => $media,
            'model_choices' => $modelChoices,
            'showcase_media' => $showcaseMedia,
            'size_chart_media' => $sizeChartMedia,
            'requires_model_choice' => $requiresModelChoice,
            'requires_size_chart' => $requiresSizeChart,
            'rules' => $rules,
        ];
    }

    private function detectSalesIntent(string $matchSource): string
    {
        if ($matchSource === '') {
            return 'unknown';
        }

        if ($this->containsAny($matchSource, ['модел', 'варіант', 'асортимент', 'каталог', 'що є'])) {
            return 'catalog';
        }

        if ($this->containsAny($matchSource, ['ціна', 'скільки', 'вартіст', 'кошту'])) {
            return 'price';
        }

        if ($this->containsAny($matchSource, ['розмір', 'розмірн', 'стоп', 'см', 'устілк']) || $this->looksLikeSizeValue($matchSource)) {
            return 'size';
        }

        if ($this->containsAny($matchSource, ['фото', 'картин', 'зображ', 'покаж', 'колаж', 'скинь', 'надішл'])) {
            return 'photo';
        }

        if ($this->containsAny($matchSource, ['куп', 'замов', 'оформ', 'брон'])) {
            return 'order';
        }

        if ($this->containsAny($matchSource, ['достав', 'місто', 'нова пошта', 'укрпошта'])) {
            return 'delivery';
        }

        if ($this->containsAny($matchSource, ['оплат', 'передоплат', 'накладен'])) {
            return 'payment';
        }

        if ($this->containsAny($matchSource, ['наявн', 'є в наяв', 'в наяв'])) {
            return 'availability';
        }

        return 'unknown';
    }

    private function intentNeedsModelChoice(string $intent): bool
    {
        return in_array($intent, ['catalog', 'price', 'size', 'photo', 'order', 'delivery', 'payment', 'availability'], true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $offeredModels
     * @return array<string, mixed>|null
     */
    private function resolveOfferedModelSelection(string $matchSource, array $offeredModels): ?array
    {
        $normalized = $this->normalizeOfferedModels($offeredModels);
        if ($normalized === [] || $matchSource === '') {
            return null;
        }

        $selectedNumber = $this->extractModelChoiceNumber($matchSource);
        if ($selectedNumber !== null) {
            foreach ($normalized as $item) {
                if ((int) ($item['number'] ?? 0) === $selectedNumber) {
                    return $item;
                }
            }
        }

        foreach ($normalized as $item) {
            $sku = $this->normalizeForMatch((string) ($item['sku'] ?? ''));
            if ($sku !== '' && Str::contains($matchSource, $sku)) {
                return $item;
            }

            $title = $this->normalizeForMatch((string) ($item['title'] ?? ''));
            if ($title !== '' && Str::contains($matchSource, $title)) {
                return $item;
            }
        }

        return null;
    }

    private function extractModelChoiceNumber(string $matchSource): ?int
    {
        if ($matchSource === '') {
            return null;
        }

        if (preg_match('/(?:модель|номер|№|#)\s*([1-9][0-9]?)/u', $matchSource, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/^\s*([1-9][0-9]?)\s*$/u', $matchSource, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/\b([1-9][0-9]?)\b/u', $matchSource, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @param  array<string, mixed>|null  $selectedFromOffer
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null
     */
    private function resolveSelectedProduct(
        array $products,
        string $matchSource,
        ?array $selectedFromOffer,
        array $state
    ): ?array {
        if ($products === []) {
            return null;
        }

        if ($selectedFromOffer !== null && !empty($selectedFromOffer['product_id'])) {
            $fromOffer = collect($products)->first(
                fn (array $product) => (int) ($product['id'] ?? 0) === (int) $selectedFromOffer['product_id']
            );
            if ($fromOffer !== null) {
                return $fromOffer;
            }
        }

        $matchedByText = $this->matchProductBySource($products, $matchSource);
        if ($matchedByText !== null) {
            return $matchedByText;
        }

        if (!empty($state['selected_product_id'])) {
            $fromState = collect($products)->first(
                fn (array $product) => (int) ($product['id'] ?? 0) === (int) $state['selected_product_id']
            );
            if ($fromState !== null) {
                return $fromState;
            }
        }

        if (count($products) === 1) {
            return $products[0];
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @return array<string, mixed>|null
     */
    private function matchProductBySource(array $products, string $matchSource): ?array
    {
        if ($matchSource === '') {
            return null;
        }

        $tokens = array_values(array_filter(
            preg_split('/[\s,.;:!?()"\'«»\-\/]+/u', $matchSource) ?: [],
            fn (string $token) => mb_strlen($token) >= 3
        ));

        $best = null;
        $bestScore = 0;

        foreach ($products as $product) {
            $score = 0;
            $title = $this->normalizeForMatch((string) ($product['title'] ?? ''));
            $sku = $this->normalizeForMatch((string) ($product['sku'] ?? ''));

            if ($sku !== '' && Str::contains($matchSource, $sku)) {
                $score += 20;
            }

            if ($title !== '' && Str::contains($matchSource, $title)) {
                $score += 16;
            }

            foreach ($tokens as $token) {
                if ($title !== '' && Str::contains($title, $token)) {
                    $score += 3;
                }
                if ($sku !== '' && Str::contains($sku, $token)) {
                    $score += 6;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $product;
            }
        }

        return $bestScore >= 8 ? $best : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @param  array<string, mixed>|null  $selectedTopic
     * @return array<int, array<string, mixed>>
     */
    private function buildModelChoices(array $products, ?array $selectedTopic): array
    {
        $models = [];

        foreach ($products as $index => $product) {
            $models[] = [
                'number' => $index + 1,
                'topic_id' => (int) ($selectedTopic['id'] ?? ($product['topic_id'] ?? 0)) ?: null,
                'topic_name' => (string) ($selectedTopic['name'] ?? ''),
                'product_id' => isset($product['id']) ? (int) $product['id'] : null,
                'title' => (string) ($product['title'] ?? ''),
                'sku' => (string) ($product['sku'] ?? ''),
                'price' => array_key_exists('price', $product) && $product['price'] !== null
                    ? (float) $product['price']
                    : null,
                'sizes' => is_array($product['sizes'] ?? null) ? $product['sizes'] : [],
                'photo_url' => (string) ($product['photo_url'] ?? ''),
            ];
        }

        return $this->normalizeOfferedModels($models);
    }

    /**
     * @param  array<int, array<string, mixed>>  $media
     * @param  array<int, array<string, mixed>>  $products
     * @return array<int, array<string, string>>
     */
    private function resolveShowcaseMedia(array $media, array $products): array
    {
        $items = [];
        $acceptedTypes = ['collage', 'palette', 'promo', 'image'];

        foreach ($acceptedTypes as $type) {
            foreach ($media as $mediaItem) {
                if ((string) ($mediaItem['media_type'] ?? '') !== $type) {
                    continue;
                }

                $url = $this->normalizeAttachmentUrl((string) ($mediaItem['url'] ?? ''));
                if ($url === '' || isset($items[$url])) {
                    continue;
                }

                $items[$url] = [
                    'label' => (string) ($mediaItem['label'] ?? ''),
                    'media_type' => (string) ($mediaItem['media_type'] ?? 'image'),
                    'url' => $url,
                ];

            }
        }

        foreach ($products as $product) {
            $url = $this->normalizeAttachmentUrl((string) ($product['photo_url'] ?? ''));
            if ($url === '' || isset($items[$url])) {
                continue;
            }

            $items[$url] = [
                'label' => (string) ($product['title'] ?? 'Модель'),
                'media_type' => 'image',
                'url' => $url,
            ];

        }

        return array_values($items);
    }

    /**
     * @param  array<int, array<string, mixed>>  $media
     * @param  array<string, mixed>|null  $selectedProduct
     * @return array<int, array<string, string>>
     */
    private function resolveSizeChartMedia(array $media, ?array $selectedProduct): array
    {
        $sizeCharts = array_values(array_filter(
            $media,
            fn (array $mediaItem) => (string) ($mediaItem['media_type'] ?? '') === 'size_chart'
        ));

        if ($sizeCharts === []) {
            return [];
        }

        if ($selectedProduct === null) {
            $result = [];
            foreach (array_slice($sizeCharts, 0, 2) as $item) {
                $url = $this->normalizeAttachmentUrl((string) ($item['url'] ?? ''));
                if ($url === '') {
                    continue;
                }
                $result[] = [
                    'label' => (string) ($item['label'] ?? ''),
                    'media_type' => 'size_chart',
                    'url' => $url,
                ];
            }

            return $result;
        }

        $title = $this->normalizeForMatch((string) ($selectedProduct['title'] ?? ''));
        $sku = $this->normalizeForMatch((string) ($selectedProduct['sku'] ?? ''));

        usort($sizeCharts, function (array $left, array $right) use ($title, $sku) {
            $leftLabel = $this->normalizeForMatch((string) ($left['label'] ?? ''));
            $rightLabel = $this->normalizeForMatch((string) ($right['label'] ?? ''));

            $leftScore = 0;
            $rightScore = 0;

            if ($title !== '' && Str::contains($leftLabel, $title)) {
                $leftScore += 6;
            }
            if ($title !== '' && Str::contains($rightLabel, $title)) {
                $rightScore += 6;
            }

            if ($sku !== '' && Str::contains($leftLabel, $sku)) {
                $leftScore += 8;
            }
            if ($sku !== '' && Str::contains($rightLabel, $sku)) {
                $rightScore += 8;
            }

            return $rightScore <=> $leftScore;
        });

        $result = [];
        foreach ($sizeCharts as $item) {
            $url = $this->normalizeAttachmentUrl((string) ($item['url'] ?? ''));
            if ($url === '' || isset($result[$url])) {
                continue;
            }

            $result[$url] = [
                'label' => (string) ($item['label'] ?? ''),
                'media_type' => 'size_chart',
                'url' => $url,
            ];

            if (count($result) >= 2) {
                break;
            }
        }

        return array_values($result);
    }

    private function looksLikeSizeValue(string $matchSource): bool
    {
        return preg_match('/\b(3[0-9]|4[0-7])\b/u', $matchSource) === 1
            || preg_match('/\b\d{1,2}(?:[.,]\d)?\s*(см|cm)\b/u', $matchSource) === 1;
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function containsAny(string $source, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (Str::contains($source, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function topicProductsPayload(ChatAiTopic $topic): array
    {
        $items = [];

        foreach ($topic->topicProducts as $topicProduct) {
            $product = $topicProduct->product;
            if (!$product) {
                continue;
            }

            $sizes = $product->variants
                ->filter(fn ($variant) => (bool) $variant->is_active && (int) $variant->stock_qty > 0)
                ->pluck('size')
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($sizes === []) {
                $sizes = $product->variants
                    ->filter(fn ($variant) => (bool) $variant->is_active)
                    ->pluck('size')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
            }

            $items[] = [
                'id' => (int) $product->id,
                'topic_id' => (int) $topic->id,
                'title' => (string) $product->title,
                'sku' => (string) ($product->sku ?? ''),
                'price' => $product->sale_price !== null ? (float) $product->sale_price : null,
                'sizes' => $sizes,
                'photo_url' => (string) ($product->main_photo_url ?? ''),
            ];

            if (count($items) >= 10) {
                break;
            }
        }

        return $items;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function topicMediaPayload(ChatAiTopic $topic): array
    {
        $items = [];

        foreach ($topic->mediaItems as $mediaItem) {
            $url = trim((string) ($mediaItem->url ?: ($mediaItem->savedFile?->url ?? '')));
            if ($url === '') {
                continue;
            }

            $items[] = [
                'label' => (string) $mediaItem->label,
                'media_type' => (string) $mediaItem->media_type,
                'url' => $url,
            ];

            if (count($items) >= 10) {
                break;
            }
        }

        return $items;
    }

    private function extractMessageForMatching(ChatMessage $message): string
    {
        $text = trim((string) $message->text);
        if ($text !== '') {
            return $text;
        }

        if ($message->attachments->isNotEmpty()) {
            $types = $message->attachments
                ->pluck('attachment_type')
                ->filter()
                ->implode(' ');

            return $types !== '' ? $types : '[вкладення]';
        }

        return '';
    }

    private function normalizeForMatch(string $value): string
    {
        $normalized = mb_strtolower($value);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function formatPrice(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function formatLatestInbound(ChatMessage $message): string
    {
        $text = trim((string) $message->text);

        if ($text !== '') {
            return $this->limitText($text, 600);
        }

        if ($message->attachments->isNotEmpty()) {
            $types = $message->attachments
                ->pluck('attachment_type')
                ->filter()
                ->unique()
                ->implode(', ');

            return '[клієнт надіслав вкладення: ' . $types . ']';
        }

        return '[порожнє повідомлення]';
    }

    /**
     * @return array<string, mixed>
     */
    private function decisionSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'should_reply' => ['type' => 'boolean'],
                'reply_text' => ['type' => 'string'],
                'handoff_required' => ['type' => 'boolean'],
                'handoff_reason' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'lead_status' => [
                    'type' => 'string',
                    'enum' => ['new', 'qualifying', 'qualified', 'handoff', 'support', 'spam'],
                ],
                'collected_data' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_name' => ['type' => 'string'],
                        'phone' => ['type' => 'string'],
                        'product_interest' => ['type' => 'string'],
                        'budget' => ['type' => 'string'],
                        'timeline' => ['type' => 'string'],
                        'city' => ['type' => 'string'],
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => [
                        'customer_name',
                        'phone',
                        'product_interest',
                        'budget',
                        'timeline',
                        'city',
                        'notes',
                    ],
                    'additionalProperties' => false,
                ],
                'sales_slots' => [
                    'type' => 'object',
                    'properties' => [
                        'size' => ['type' => 'string'],
                        'color' => ['type' => 'string'],
                        'qty' => ['type' => 'string'],
                        'city' => ['type' => 'string'],
                        'warehouse_address' => ['type' => 'string'],
                        'payment_method' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                        'phone' => ['type' => 'string'],
                    ],
                    'required' => [
                        'size',
                        'color',
                        'qty',
                        'city',
                        'warehouse_address',
                        'payment_method',
                        'name',
                        'phone',
                    ],
                    'additionalProperties' => false,
                ],
                'attachment_urls' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
            ],
            'required' => [
                'should_reply',
                'reply_text',
                'handoff_required',
                'handoff_reason',
                'summary',
                'lead_status',
                'collected_data',
                'sales_slots',
                'attachment_urls',
            ],
            'additionalProperties' => false,
        ];
    }

    private function sendAiReply(
        ChatConversation $conversation,
        string $replyText,
        bool $handoffRequired,
        string $leadStatus,
        string $model,
        array $attachments = []
    ): ?ChatMessage {
        $customer = $conversation->customer;
        $contact = $conversation->contact;

        if (!$customer || !$contact) {
            throw new RuntimeException('Для AI-відповіді не вистачає customer/contact.');
        }

        $sentAt = now(config('app.timezone', 'Europe/Kyiv'));
        $lastMessage = null;

        if ($attachments !== []) {
            foreach ($attachments as $attachment) {
                $metaResult = $this->metaService->sendMessage(
                    $customer,
                    '',
                    [$attachment['meta_payload']],
                    $contact->platform,
                    $contact->external_user_id
                );

                if (!$metaResult) {
                    throw new RuntimeException('Meta API не прийняв AI-повідомлення з вкладенням.');
                }

                $lastMessage = $this->chatService->storeMessage($conversation, [
                    'direction' => 'outbound',
                    'external_message_id' => $metaResult['message_id'] ?? null,
                    'delivery_status' => 'sent',
                    'source' => 'system',
                    'text' => null,
                    'sent_at' => $sentAt,
                    'meta' => [
                        'ai_generated' => true,
                        'provider' => 'openai',
                        'model' => $model,
                        'handoff_required' => $handoffRequired,
                        'lead_status' => $leadStatus,
                    ],
                ], [$attachment['stored_attachment']]);

                $conversation = $this->chatService->updateConversationAfterMessage($conversation, $lastMessage, false);
            }

            if (trim($replyText) !== '') {
                $metaResult = $this->metaService->sendMessage(
                    $customer,
                    $replyText,
                    [],
                    $contact->platform,
                    $contact->external_user_id
                );

                if (!$metaResult) {
                    throw new RuntimeException('Meta API не прийняв текст після вкладень AI.');
                }

                $lastMessage = $this->chatService->storeMessage($conversation, [
                    'direction' => 'outbound',
                    'external_message_id' => $metaResult['message_id'] ?? null,
                    'delivery_status' => 'sent',
                    'source' => 'system',
                    'text' => $replyText,
                    'sent_at' => $sentAt,
                    'meta' => [
                        'ai_generated' => true,
                        'provider' => 'openai',
                        'model' => $model,
                        'handoff_required' => $handoffRequired,
                        'lead_status' => $leadStatus,
                    ],
                ]);

                $conversation = $this->chatService->updateConversationAfterMessage($conversation, $lastMessage, false);
            }
        } else {
            $metaResult = $this->metaService->sendMessage(
                $customer,
                $replyText,
                [],
                $contact->platform,
                $contact->external_user_id
            );

            if (!$metaResult) {
                throw new RuntimeException('Meta API не прийняв AI-повідомлення.');
            }

            $lastMessage = $this->chatService->storeMessage($conversation, [
                'direction' => 'outbound',
                'external_message_id' => $metaResult['message_id'] ?? null,
                'delivery_status' => 'sent',
                'source' => 'system',
                'text' => $replyText,
                'sent_at' => $sentAt,
                'meta' => [
                    'ai_generated' => true,
                    'provider' => 'openai',
                    'model' => $model,
                    'handoff_required' => $handoffRequired,
                    'lead_status' => $leadStatus,
                ],
            ]);

            $conversation = $this->chatService->updateConversationAfterMessage($conversation, $lastMessage, false);
        }

        if ($handoffRequired) {
            $this->moveConversationToStage($conversation, 'new');
        }

        return $lastMessage;
    }

    private function moveConversationToStage(ChatConversation $conversation, string $stageCode): void
    {
        $stageId = ChatStage::query()
            ->where('code', $stageCode)
            ->value('id');

        if (!$stageId) {
            return;
        }

        $conversation->stage_id = $stageId;
        $conversation->save();
    }

    private function normalizeSalesState(string $state): string
    {
        $state = match ($state) {
            'await_model' => 'product',
            'await_variant' => 'variant',
            'await_qty' => 'qty',
            'await_delivery' => 'delivery',
            'await_payment' => 'payment',
            'await_contact' => 'contact',
            'confirm_order' => 'confirm',
            default => $state,
        };

        return in_array($state, self::SALES_STATES, true) ? $state : 'idle';
    }

    private function normalizeSalesSlotKey(mixed $slot): ?string
    {
        if (!is_string($slot)) {
            return null;
        }

        $slot = trim($slot);
        $allowed = [
            'product_id',
            'size',
            'color',
            'qty',
            'city',
            'warehouse_address',
            'payment_method',
            'name',
            'phone',
        ];

        return in_array($slot, $allowed, true) ? $slot : null;
    }

    /**
     * @param  mixed  $slots
     * @return array<string, mixed>
     */
    private function normalizeSalesSlots(mixed $slots): array
    {
        $slots = is_array($slots) ? $slots : [];
        $qty = null;
        if (isset($slots['qty'])) {
            if (is_numeric($slots['qty'])) {
                $qty = max(1, (int) $slots['qty']);
            } elseif (is_string($slots['qty']) && preg_match('/\d+/u', $slots['qty'], $matches) === 1) {
                $qty = max(1, (int) $matches[0]);
            }
        }

        $productId = isset($slots['product_id']) ? (int) $slots['product_id'] : null;
        $productId = $productId && $productId > 0 ? $productId : null;

        return [
            'product_id' => $productId,
            'size' => $this->limitText((string) ($slots['size'] ?? ''), 64),
            'color' => $this->limitText((string) ($slots['color'] ?? ''), 64),
            'qty' => $qty,
            'city' => $this->limitText((string) ($slots['city'] ?? ''), 120),
            'warehouse_address' => $this->limitText((string) ($slots['warehouse_address'] ?? ''), 255),
            'payment_method' => $this->limitText((string) ($slots['payment_method'] ?? ''), 120),
            'name' => $this->limitText((string) ($slots['name'] ?? ''), 120),
            'phone' => $this->limitText((string) ($slots['phone'] ?? ''), 64),
        ];
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    private function formatSalesSlotsForPrompt(array $slots): string
    {
        $slots = $this->normalizeSalesSlots($slots);
        $parts = [];

        foreach ($slots as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $parts[] = $key . '=' . (is_int($value) ? $value : $this->limitText((string) $value, 80));
        }

        return $parts === [] ? 'порожньо' : implode('; ', $parts);
    }

    private function humanSalesSlotLabel(string $slot): string
    {
        return match ($slot) {
            'product_id' => 'модель товару',
            'size' => 'розмір',
            'color' => 'колір',
            'qty' => 'кількість',
            'city' => 'місто',
            'warehouse_address' => 'відділення, поштомат або адреса',
            'payment_method' => 'спосіб оплати',
            'name' => 'імʼя отримувача',
            'phone' => 'номер телефону',
            default => $slot,
        };
    }

    /**
     * @param  mixed  $offeredModels
     * @return array<int, array<string, mixed>>
     */
    private function normalizeOfferedModels(mixed $offeredModels): array
    {
        if (!is_array($offeredModels)) {
            return [];
        }

        $normalized = [];
        foreach ($offeredModels as $item) {
            if (!is_array($item)) {
                continue;
            }

            $number = isset($item['number']) ? (int) $item['number'] : (count($normalized) + 1);
            if ($number < 1 || $number > 99 || isset($normalized[$number])) {
                continue;
            }

            $sizes = array_values(array_unique(array_filter(array_map(
                fn ($size) => $this->limitText((string) $size, 16),
                is_array($item['sizes'] ?? null) ? $item['sizes'] : []
            ))));

            $price = null;
            if (array_key_exists('price', $item) && is_numeric($item['price'])) {
                $price = (float) $item['price'];
            }

            $topicId = isset($item['topic_id']) ? (int) $item['topic_id'] : null;
            $productId = isset($item['product_id']) ? (int) $item['product_id'] : null;
            $topicId = $topicId && $topicId > 0 ? $topicId : null;
            $productId = $productId && $productId > 0 ? $productId : null;

            $normalized[$number] = [
                'number' => $number,
                'topic_id' => $topicId,
                'topic_name' => $this->limitText((string) ($item['topic_name'] ?? ''), 120),
                'product_id' => $productId,
                'title' => $this->limitText((string) ($item['title'] ?? ''), 180),
                'sku' => $this->limitText((string) ($item['sku'] ?? ''), 80),
                'price' => $price,
                'sizes' => $sizes,
                'photo_url' => $this->normalizeAttachmentUrl((string) ($item['photo_url'] ?? '')),
                'collage_url' => $this->normalizeAttachmentUrl((string) ($item['collage_url'] ?? '')),
            ];
        }

        ksort($normalized);

        return array_values($normalized);
    }

    /**
     * @param  array<string, mixed>  $knowledgeContext
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function resolveSalesSlots(
        ChatConversation $conversation,
        ChatMessage $message,
        array $knowledgeContext,
        array $state
    ): array {
        $slots = $this->normalizeSalesSlots($state['sales_slots'] ?? []);
        $customer = $conversation->customer;
        $lead = is_array($state['lead'] ?? null) ? $state['lead'] : [];
        $matchSource = $this->normalizeForMatch((string) $message->text);

        if ($customer) {
            if ($slots['name'] === '') {
                $slots['name'] = $this->limitText((string) ($customer->full_name ?? ''), 120);
            }
            if ($slots['phone'] === '') {
                $slots['phone'] = $this->limitText((string) ($customer->phone ?? ''), 64);
            }
        }

        if ($slots['name'] === '' && !empty($lead['customer_name'])) {
            $slots['name'] = $this->limitText((string) $lead['customer_name'], 120);
        }
        if ($slots['phone'] === '' && !empty($lead['phone'])) {
            $slots['phone'] = $this->limitText((string) $lead['phone'], 64);
        }
        if ($slots['city'] === '' && !empty($lead['city'])) {
            $slots['city'] = $this->limitText((string) $lead['city'], 120);
        }

        if (!empty($knowledgeContext['selected_product']['id'])) {
            $slots['product_id'] = (int) $knowledgeContext['selected_product']['id'];
        }

        $matchedSize = $this->extractMatchedSize($matchSource, $knowledgeContext);
        if ($matchedSize !== null) {
            $slots['size'] = $matchedSize;
        } elseif ($slots['size'] === '') {
            $rawSize = $this->extractRawSizeFromMessage($matchSource);
            if ($rawSize !== null) {
                $slots['size'] = $rawSize;
            }
        }

        $color = $this->extractColorFromMessage($matchSource);
        if ($color !== null) {
            $slots['color'] = $color;
        }

        $qty = $this->extractQtyFromMessage((string) $message->text);
        if ($qty !== null) {
            $slots['qty'] = $qty;
        }

        $paymentMethod = $this->extractPaymentMethodFromMessage($matchSource);
        if ($paymentMethod !== null) {
            $slots['payment_method'] = $paymentMethod;
        }

        $phone = $this->extractPhoneFromMessage((string) $message->text);
        if ($phone !== null) {
            $slots['phone'] = $phone;
        }

        $name = $this->extractNameFromMessage((string) $message->text);
        if ($name !== null) {
            $slots['name'] = $name;
        }

        $city = $this->extractCityFromMessage((string) $message->text);
        if ($city !== null) {
            $slots['city'] = $city;
        }

        $warehouseAddress = $this->extractWarehouseAddressFromMessage((string) $message->text);
        if ($warehouseAddress !== null) {
            $slots['warehouse_address'] = $warehouseAddress;
        }

        return $this->normalizeSalesSlots($slots);
    }

    /**
     * @param  array<string, mixed>  $currentSlots
     * @param  mixed  $decisionSlots
     * @param  array<string, mixed>  $knowledgeContext
     * @return array<string, mixed>
     */
    private function mergeSalesSlots(
        array $currentSlots,
        mixed $decisionSlots,
        array $knowledgeContext,
        ChatConversation $conversation
    ): array {
        $slots = $this->normalizeSalesSlots($currentSlots);
        $decisionSlots = $this->normalizeSalesSlots($decisionSlots);

        foreach ($decisionSlots as $key => $value) {
            if ($key === 'product_id') {
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $slots[$key] = $value;
        }

        if (!empty($knowledgeContext['selected_product']['id'])) {
            $slots['product_id'] = (int) $knowledgeContext['selected_product']['id'];
        }

        if ($slots['name'] === '' && $conversation->customer) {
            $slots['name'] = $this->limitText((string) ($conversation->customer->full_name ?? ''), 120);
        }
        if ($slots['phone'] === '' && $conversation->customer) {
            $slots['phone'] = $this->limitText((string) ($conversation->customer->phone ?? ''), 64);
        }

        if ($slots['size'] !== '') {
            $matchedSize = $this->extractMatchedSize(
                $this->normalizeForMatch((string) $slots['size']),
                $knowledgeContext
            );
            if ($matchedSize !== null) {
                $slots['size'] = $matchedSize;
            }
        }

        return $this->normalizeSalesSlots($slots);
    }

    /**
     * @param  array<string, mixed>  $knowledgeContext
     * @param  array<string, mixed>  $salesSlots
     */
    private function determineNextRequiredSlot(
        array $knowledgeContext,
        array $salesSlots,
        ?ChatMessage $message = null
    ): ?string {
        $salesSlots = $this->normalizeSalesSlots($salesSlots);
        $source = $message ? $this->normalizeForMatch((string) $message->text) : '';

        if ((bool) ($knowledgeContext['requires_model_choice'] ?? false) || empty($salesSlots['product_id'])) {
            return 'product_id';
        }

        if (!$this->isSelectedSizeValid($knowledgeContext, $salesSlots)) {
            return 'size';
        }

        if ($salesSlots['color'] === '' && ($source !== '' && Str::contains($source, 'колір'))) {
            return 'color';
        }

        if ($salesSlots['qty'] === null) {
            return 'qty';
        }

        if ($salesSlots['city'] === '') {
            return 'city';
        }

        if ($salesSlots['warehouse_address'] === '') {
            return 'warehouse_address';
        }

        if ($salesSlots['payment_method'] === '') {
            return 'payment_method';
        }

        if ($salesSlots['name'] === '') {
            return 'name';
        }

        if ($salesSlots['phone'] === '') {
            return 'phone';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $knowledgeContext
     * @param  array<string, mixed>  $salesSlots
     */
    private function isOrderReady(array $knowledgeContext, array $salesSlots): bool
    {
        return !empty($salesSlots['product_id'])
            && $this->determineNextRequiredSlot($knowledgeContext, $salesSlots) === null;
    }

    /**
     * @param  array<string, mixed>  $knowledgeContext
     * @param  array<string, mixed>  $salesSlots
     */
    private function isSelectedSizeValid(array $knowledgeContext, array $salesSlots): bool
    {
        $size = trim((string) ($salesSlots['size'] ?? ''));
        if ($size === '') {
            return false;
        }

        $candidateSizes = array_values(array_filter((array) data_get($knowledgeContext, 'selected_product.sizes', [])));
        if ($candidateSizes === []) {
            return true;
        }

        return $this->extractMatchedSize($this->normalizeForMatch($size), $knowledgeContext) !== null;
    }

    /**
     * @param  array<string, mixed>  $knowledgeContext
     */
    private function extractMatchedSize(string $matchSource, array $knowledgeContext): ?string
    {
        $candidateSizes = array_values(array_filter((array) data_get($knowledgeContext, 'selected_product.sizes', [])));
        if ($candidateSizes === []) {
            return null;
        }

        $best = null;
        $bestScore = 0;
        foreach ($candidateSizes as $candidate) {
            $score = 0;
            $candidate = (string) $candidate;
            $candidateNormalized = $this->normalizeForMatch($candidate);
            if ($candidateNormalized !== '' && Str::contains($matchSource, $candidateNormalized)) {
                $score += 100;
            }

            foreach ($this->sizeTokensFromString($candidateNormalized) as $token) {
                if ($token !== '' && Str::contains($matchSource, $token)) {
                    $score += mb_strlen($token) >= 4 ? 20 : 10;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $bestScore >= 10 ? $best : null;
    }

    /**
     * @return array<int, string>
     */
    private function sizeTokensFromString(string $value): array
    {
        preg_match_all('/\d{1,2}(?:\/\d{1,2})?(?:[.,]\d)?/u', $value, $matches);

        return array_values(array_unique(array_filter($matches[0] ?? [])));
    }

    private function extractRawSizeFromMessage(string $matchSource): ?string
    {
        if (preg_match('/\b\d{1,2}\/\d{1,2}\b/u', $matchSource, $matches) === 1) {
            return $matches[0];
        }

        if (preg_match('/\b(3[0-9]|4[0-7])\b/u', $matchSource, $matches) === 1) {
            return $matches[0];
        }

        if (preg_match('/\b\d{1,2}(?:[.,]\d)?\s*(?:см|cm)\b/u', $matchSource, $matches) === 1) {
            return str_replace('cm', 'см', $matches[0]);
        }

        return null;
    }

    private function extractColorFromMessage(string $matchSource): ?string
    {
        $colors = [
            'чорний',
            'білий',
            'сірий',
            'бежевий',
            'коричневий',
            'рожевий',
            'червоний',
            'синій',
            'голубий',
            'зелений',
            'жовтий',
            'молочний',
            'бордовий',
        ];

        foreach ($colors as $color) {
            if (Str::contains($matchSource, $color)) {
                return $color;
            }
        }

        return null;
    }

    private function extractQtyFromMessage(string $source): ?int
    {
        if (preg_match('/\b(\d{1,2})\s*(?:пар|пара|пари|шт|штук)\b/ui', $source, $matches) === 1) {
            return max(1, (int) $matches[1]);
        }

        $source = $this->normalizeForMatch($source);
        $map = [
            'одну пару' => 1,
            'одна пара' => 1,
            'дві пари' => 2,
            'три пари' => 3,
            'чотири пари' => 4,
            'пʼять пар' => 5,
            "п'ять пар" => 5,
        ];

        foreach ($map as $phrase => $qty) {
            if (Str::contains($source, $phrase)) {
                return $qty;
            }
        }

        return null;
    }

    private function extractPaymentMethodFromMessage(string $matchSource): ?string
    {
        if ($this->containsAny($matchSource, ['післяплат', 'накладен'])) {
            return 'післяплата';
        }

        if ($this->containsAny($matchSource, ['картою', 'на карт', 'повна оплат', 'оплата онлайн'])) {
            return 'повна оплата';
        }

        return null;
    }

    private function extractPhoneFromMessage(string $text): ?string
    {
        if (preg_match('/(?:\+?38)?[\s\-\(]*0\d{2}[\s\-\)]*\d{3}[\s\-]*\d{2}[\s\-]*\d{2}/u', $text, $matches) !== 1) {
            return null;
        }

        return $this->limitText(trim((string) $matches[0]), 64);
    }

    private function extractNameFromMessage(string $text): ?string
    {
        if (preg_match('/(?:мене звати|я\s+)([А-ЯІЇЄҐA-Z][а-яіїєґa-z\'’\-]+)/u', $text, $matches) === 1) {
            return $this->limitText((string) $matches[1], 120);
        }

        return null;
    }

    private function extractCityFromMessage(string $text): ?string
    {
        if (preg_match('/(?:м\.|місто)\s*([А-ЯІЇЄҐA-Z][а-яіїєґa-z\'’\-]+)/u', $text, $matches) === 1) {
            return $this->limitText((string) $matches[1], 120);
        }

        return null;
    }

    private function extractWarehouseAddressFromMessage(string $text): ?string
    {
        if (preg_match('/(відділення\s*\d+|поштомат\s*\d+|вул\.[^,.]+(?:\d+)?)/ui', $text, $matches) === 1) {
            return $this->limitText(trim((string) $matches[1]), 255);
        }

        return null;
    }

    /**
     * @param  array<string, string>  $lead
     * @param  array<string, mixed>  $salesSlots
     * @param  array<string, mixed>  $knowledgeContext
     * @return array<string, string>
     */
    private function mergeLeadWithSalesSlots(array $lead, array $salesSlots, array $knowledgeContext): array
    {
        $salesSlots = $this->normalizeSalesSlots($salesSlots);
        $lead['customer_name'] = $lead['customer_name'] !== '' ? $lead['customer_name'] : (string) $salesSlots['name'];
        $lead['phone'] = $lead['phone'] !== '' ? $lead['phone'] : (string) $salesSlots['phone'];
        $lead['city'] = $lead['city'] !== '' ? $lead['city'] : (string) $salesSlots['city'];

        if ($lead['product_interest'] === '' && !empty($knowledgeContext['selected_product']['title'])) {
            $lead['product_interest'] = $this->limitText((string) $knowledgeContext['selected_product']['title'], 255);
        }

        $noteParts = array_filter([
            $salesSlots['size'] !== '' ? 'розмір: ' . $salesSlots['size'] : '',
            $salesSlots['color'] !== '' ? 'колір: ' . $salesSlots['color'] : '',
            $salesSlots['qty'] !== null ? 'кількість: ' . $salesSlots['qty'] : '',
            $salesSlots['warehouse_address'] !== '' ? 'доставка: ' . $salesSlots['warehouse_address'] : '',
            $salesSlots['payment_method'] !== '' ? 'оплата: ' . $salesSlots['payment_method'] : '',
        ]);

        if ($lead['notes'] === '' && $noteParts !== []) {
            $lead['notes'] = $this->limitText(implode('; ', $noteParts), 500);
        }

        return $this->normalizeLeadPayload($lead);
    }

    /**
     * @param  array<string, mixed>  $knowledgeContext
     * @param  array<string, mixed>  $salesSlots
     */
    private function buildConfirmationReply(array $knowledgeContext, array $salesSlots): string
    {
        $salesSlots = $this->normalizeSalesSlots($salesSlots);
        $productTitle = (string) data_get($knowledgeContext, 'selected_product.title', 'обрана модель');
        $parts = [
            'Підтверджую замовлення: ' . $this->limitText($productTitle, 120),
        ];

        if ($salesSlots['size'] !== '') {
            $parts[] = 'розмір ' . $salesSlots['size'];
        }
        if ($salesSlots['color'] !== '') {
            $parts[] = 'колір ' . $salesSlots['color'];
        }
        if ($salesSlots['qty'] !== null) {
            $parts[] = 'кількість ' . $salesSlots['qty'];
        }
        if ($salesSlots['city'] !== '') {
            $parts[] = 'місто ' . $salesSlots['city'];
        }
        if ($salesSlots['warehouse_address'] !== '') {
            $parts[] = $salesSlots['warehouse_address'];
        }
        if ($salesSlots['payment_method'] !== '') {
            $parts[] = 'оплата ' . $salesSlots['payment_method'];
        }

        $text = implode(', ', $parts) . '. Передаю замовлення менеджеру для оформлення.';

        return $this->limitText($text, 600);
    }

    /**
     * @param  array<string, mixed>  $knowledgeContext
     * @param  array<string, mixed>  $salesSlots
     */
    private function buildNextSlotQuestion(array $knowledgeContext, array $salesSlots, string $slot): string
    {
        $salesSlots = $this->normalizeSalesSlots($salesSlots);

        return match ($slot) {
            'product_id' => 'Добрий день! Уточніть, будь ласка, яка саме модель вас цікавить. Напишіть номер моделі та ваш розмір.',
            'size' => (bool) ($knowledgeContext['requires_size_chart'] ?? false)
                ? 'Надсилаю розмірну сітку у вкладенні. Напишіть, будь ласка, ваш розмір або довжину стопи в см.'
                : 'Напишіть, будь ласка, який саме розмір вам потрібен.',
            'color' => 'Підкажіть, будь ласка, який колір вам потрібен.',
            'qty' => $salesSlots['size'] !== '' && !empty($knowledgeContext['selected_product']['title'])
                ? 'Розмір ' . $salesSlots['size'] . ' для моделі "' . $this->limitText((string) $knowledgeContext['selected_product']['title'], 80) . '" прийняв. Скільки пар потрібно?'
                : 'Скільки пар вам потрібно?',
            'city' => 'Підкажіть, будь ласка, в яке місто відправляти замовлення.',
            'warehouse_address' => 'Напишіть, будь ласка, відділення, поштомат або повну адресу доставки.',
            'payment_method' => 'Який спосіб оплати вам зручний: післяплата чи повна оплата?',
            'name' => 'На яке імʼя оформити замовлення?',
            'phone' => 'Напишіть, будь ласка, номер телефону для оформлення замовлення.',
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $knowledgeContext
     */
    private function enforceSalesReplyText(string $replyText, array $knowledgeContext): string
    {
        if ((bool) ($knowledgeContext['order_ready'] ?? false)) {
            return $this->buildConfirmationReply(
                $knowledgeContext,
                (array) ($knowledgeContext['sales_slots'] ?? [])
            );
        }

        if ($replyText === '') {
            return $replyText;
        }

        if (!empty($knowledgeContext['next_required_slot']) && mb_substr_count($replyText, '?') > 1) {
            return $this->buildNextSlotQuestion(
                $knowledgeContext,
                (array) ($knowledgeContext['sales_slots'] ?? []),
                (string) $knowledgeContext['next_required_slot']
            );
        }

        return $replyText;
    }

    /**
     * @param  array<string, mixed>  $knowledgeContext
     */
    private function fallbackReplyText(string $replyText, array $knowledgeContext): string
    {
        if ($replyText !== '') {
            return $replyText;
        }

        if ((bool) ($knowledgeContext['order_ready'] ?? false)) {
            return $this->buildConfirmationReply(
                $knowledgeContext,
                (array) ($knowledgeContext['sales_slots'] ?? [])
            );
        }

        if ((bool) ($knowledgeContext['requires_model_choice'] ?? false)) {
            $choices = (array) ($knowledgeContext['model_choices'] ?? []);
            $labels = [];
            foreach ($choices as $choice) {
                $number = (int) ($choice['number'] ?? 0);
                if ($number <= 0) {
                    continue;
                }
                $labels[] = '#' . $number . ' ' . $this->limitText((string) ($choice['title'] ?? ''), 40);
            }

            $suffix = $labels !== []
                ? ' Доступні моделі: ' . implode(', ', $labels) . '.'
                : '';

            return $this->limitText(
                'Добрий день! Уточніть, будь ласка, яка саме модель вас цікавить. Напишіть номер моделі та ваш розмір.' . $suffix,
                600
            );
        }

        if (!empty($knowledgeContext['next_required_slot'])) {
            return $this->limitText(
                $this->buildNextSlotQuestion(
                    $knowledgeContext,
                    (array) ($knowledgeContext['sales_slots'] ?? []),
                    (string) $knowledgeContext['next_required_slot']
                ),
                600
            );
        }

        if ((bool) ($knowledgeContext['requires_size_chart'] ?? false)) {
            $productTitle = $this->limitText((string) data_get($knowledgeContext, 'selected_product.title', ''), 120);
            $prefix = $productTitle !== '' ? ('Для моделі "' . $productTitle . '" ') : '';

            return $this->limitText(
                $prefix . 'надсилаю розмірну сітку у вкладенні. Напишіть ваш розмір або довжину стопи в см.',
                600
            );
        }

        return $replyText;
    }

    /**
     * @param  array<string, mixed>  $currentState
     * @param  array<string, mixed>  $knowledgeContext
     * @return array<string, mixed>
     */
    private function buildSalesStatePatch(array $currentState, array $knowledgeContext, bool $handoffRequired): array
    {
        $patch = [
            'sales_state' => $this->normalizeSalesState((string) ($currentState['sales_state'] ?? 'idle')),
            'selected_topic_id' => isset($currentState['selected_topic_id']) ? (int) $currentState['selected_topic_id'] : null,
            'selected_product_id' => isset($currentState['selected_product_id']) ? (int) $currentState['selected_product_id'] : null,
            'offered_models' => $this->normalizeOfferedModels($currentState['offered_models'] ?? []),
            'sales_slots' => $this->normalizeSalesSlots($knowledgeContext['sales_slots'] ?? ($currentState['sales_slots'] ?? [])),
            'next_required_slot' => $this->normalizeSalesSlotKey($knowledgeContext['next_required_slot'] ?? null),
        ];

        if ($handoffRequired) {
            $patch['sales_state'] = 'handoff';

            return $patch;
        }

        if (!empty($knowledgeContext['selected_topic']['id'])) {
            $patch['selected_topic_id'] = (int) $knowledgeContext['selected_topic']['id'];
        }

        if (!empty($knowledgeContext['selected_product']['id'])) {
            $patch['selected_product_id'] = (int) $knowledgeContext['selected_product']['id'];
        }

        $modelChoices = $this->normalizeOfferedModels($knowledgeContext['model_choices'] ?? []);
        if ((bool) ($knowledgeContext['requires_model_choice'] ?? false)) {
            $patch['sales_state'] = 'product';
            $patch['selected_product_id'] = null;
            $patch['offered_models'] = $modelChoices;
            $patch['next_required_slot'] = 'product_id';

            return $patch;
        }

        $patch['offered_models'] = $modelChoices;

        if ((bool) ($knowledgeContext['order_ready'] ?? false)) {
            $patch['sales_state'] = 'confirm';

            return $patch;
        }

        $nextRequiredSlot = $this->normalizeSalesSlotKey($knowledgeContext['next_required_slot'] ?? null);
        if ($nextRequiredSlot === 'size' || $nextRequiredSlot === 'color') {
            $patch['sales_state'] = 'variant';

            return $patch;
        }

        if ($nextRequiredSlot === 'qty') {
            $patch['sales_state'] = 'stock';

            return $patch;
        }

        if ($nextRequiredSlot === 'city' || $nextRequiredSlot === 'warehouse_address') {
            $patch['sales_state'] = 'delivery';

            return $patch;
        }

        if ($nextRequiredSlot === 'payment_method') {
            $patch['sales_state'] = 'payment';

            return $patch;
        }

        if ($nextRequiredSlot === 'name' || $nextRequiredSlot === 'phone') {
            $patch['sales_state'] = 'contact';

            return $patch;
        }

        if (!empty($patch['selected_product_id'])) {
            $patch['sales_state'] = 'variant';

            return $patch;
        }

        if (!empty($patch['selected_topic_id'])) {
            $patch['sales_state'] = 'product';

            return $patch;
        }

        $patch['sales_state'] = 'intent';

        return $patch;
    }

    private function normalizeLeadStatus(string $leadStatus): string
    {
        return in_array($leadStatus, ['new', 'qualifying', 'qualified', 'handoff', 'support', 'spam'], true)
            ? $leadStatus
            : 'new';
    }

    /**
     * @param  array<string, mixed>  $lead
     * @return array<string, string>
     */
    private function normalizeLeadPayload(array $lead): array
    {
        return [
            'customer_name' => $this->limitText((string) ($lead['customer_name'] ?? ''), 120),
            'phone' => $this->limitText((string) ($lead['phone'] ?? ''), 64),
            'product_interest' => $this->limitText((string) ($lead['product_interest'] ?? ''), 255),
            'budget' => $this->limitText((string) ($lead['budget'] ?? ''), 120),
            'timeline' => $this->limitText((string) ($lead['timeline'] ?? ''), 120),
            'city' => $this->limitText((string) ($lead['city'] ?? ''), 120),
            'notes' => $this->limitText((string) ($lead['notes'] ?? ''), 500),
        ];
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    private function normalizeSummary(array $decision): string
    {
        $summary = trim((string) ($decision['summary'] ?? ''));
        if ($summary !== '') {
            return $this->limitText($summary, 500);
        }

        $lead = $this->normalizeLeadPayload($decision['collected_data'] ?? []);
        $parts = array_filter([
            $lead['product_interest'],
            $lead['budget'],
            $lead['timeline'],
            $lead['city'],
        ]);

        return $this->limitText(implode(' | ', $parts), 500);
    }

    private function sanitizeReplyText(string $text): string
    {
        $text = preg_replace('/!\[[^\]]*]\((https?:\/\/[^\s)]+)\)/ui', ' ', $text);
        $text = preg_replace_callback(
            '/\[([^\]]+)]\((https?:\/\/[^\s)]+)\)/ui',
            function (array $matches): string {
                $label = trim((string) ($matches[1] ?? ''));
                $normalized = $this->normalizeForMatch($label);

                return in_array($normalized, ['фото', 'photo', 'image', 'img', 'картинка', 'зображення'], true)
                    ? ' '
                    : $label;
            },
            $text
        );
        $text = preg_replace('/https?:\/\/\S+/ui', ' ', $text);
        $text = preg_replace('/\(\s*\)/u', ' ', $text);
        $text = preg_replace('/\[\s*]/u', ' ', $text);
        $text = preg_replace('/:\s*[—-]\s*/u', '. ', $text);
        $text = preg_replace('/\s+([,.;:!?])/u', '$1', $text);
        $text = preg_replace('/([:;,-])\s*[.]/u', '$1', $text);
        $text = preg_replace('/\s+/u', ' ', trim($text));

        return $this->limitText((string) $text, 600);
    }

    /**
     * @param  array<string, mixed>  $knowledgeContext
     * @return array<int, string>
     */
    private function extractInlineAttachmentUrls(string $replyText, array $knowledgeContext): array
    {
        if ($replyText === '') {
            return [];
        }

        $allowedMap = $this->allowedAttachmentMap($knowledgeContext);
        if ($allowedMap === []) {
            return [];
        }

        preg_match_all('/https?:\/\/[^\s)]+/ui', $replyText, $matches);
        $urls = [];

        foreach ((array) ($matches[0] ?? []) as $url) {
            $normalizedUrl = $this->normalizeAttachmentUrl((string) $url);
            if ($normalizedUrl === '' || !isset($allowedMap[$normalizedUrl])) {
                continue;
            }

            $urls[$normalizedUrl] = $normalizedUrl;
        }

        return array_values($urls);
    }

    private function limitText(string $value, int $limit): string
    {
        return Str::of($value)->squish()->limit($limit, '')->value();
    }

    private function freshConversation(ChatConversation $conversation): ?ChatConversation
    {
        return ChatConversation::query()
            ->with(['contact', 'customer', 'stage', 'assignedUser', 'lastMessage', 'lastMessage.attachments'])
            ->find($conversation->id);
    }
}
