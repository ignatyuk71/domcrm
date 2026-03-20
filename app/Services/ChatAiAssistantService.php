<?php

namespace App\Services;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatStage;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class ChatAiAssistantService
{
    private const META_KEY = 'ai';

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
            'current_product' => $this->resolveCurrentProductPayload($state),
            'current_size' => $state['current_size'] ?: null,
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

        $this->storeState($conversation, [
            'enabled' => $currentState['enabled'],
            'status' => 'processing',
            'last_inbound_message_id' => $message->id,
            'last_error' => '',
            'updated_at' => now()->toDateTimeString(),
            'summary' => $currentState['summary'],
            'lead' => $currentState['lead'],
        ]);

        $catalogReply = $this->resolveCatalogReply($conversation, $message, $currentState);
        if ($catalogReply !== null) {
            $this->finalizeCatalogReply(
                $conversation,
                $message,
                $currentState,
                $catalogReply,
                (string) $runtimeSettings['model']
            );

            return;
        }

        try {
            $decision = $this->openAiResponses->createStructuredResponse(
                $this->buildInstructions($runtimeSettings),
                $this->buildInput($conversation, $message, $runtimeSettings),
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

        $replyText = $this->sanitizeReplyText((string) ($decision['reply_text'] ?? ''));
        $handoffRequired = (bool) ($decision['handoff_required'] ?? false);
        $shouldReply = (bool) ($decision['should_reply'] ?? false) && $replyText !== '';
        $summary = $this->normalizeSummary($decision);
        $lead = $this->normalizeLeadPayload($decision['collected_data'] ?? []);
        $leadStatus = $this->normalizeLeadStatus((string) ($decision['lead_status'] ?? 'new'));
        $handoffReason = trim((string) ($decision['handoff_reason'] ?? ''));
        $sentMessage = null;

        try {
            if ($shouldReply) {
                $sentMessage = $this->sendAiReply(
                    $conversation,
                    $replyText,
                    $handoffRequired,
                    $leadStatus,
                    (string) $runtimeSettings['model']
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
            'current_product_id' => isset($stored['current_product_id']) ? (int) $stored['current_product_id'] : null,
            'current_product_title' => (string) ($stored['current_product_title'] ?? ''),
            'current_size' => (string) ($stored['current_size'] ?? ''),
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
     */
    private function buildInstructions(array $settings): string
    {
        $instructions = [
            'Ти ' . $settings['assistant_name'] . ', AI-асистент першої лінії для CRM інтернет-магазину.',
            'Спілкуйся тільки українською мовою.',
            'Ти відповідаєш першим, збираєш дані та передаєш менеджеру складні або продажні кейси.',
            'Не вигадуй ціну, наявність, строки доставки, знижки або умови оплати, якщо цього немає в контексті або каталозі.',
            'Якщо у контексті вже є поточний товар, а клієнт пише лише число на кшталт 36-45 або фразу "40 ціна", трактуй це як запит про розмір цього товару.',
            'Якщо точна ціна або наявність уже передані з каталогу, відповідай ними прямо. Якщо каталожних даних нема, не вигадуй і передавай менеджеру.',
            'Якщо клієнт хоче знижку, оплату, рекламацію, живого менеджера або запит нестандартний, став handoff_required=true.',
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

        return implode("\n", $instructions);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function buildInput(ChatConversation $conversation, ChatMessage $message, array $settings): string
    {
        $customer = $conversation->customer;
        $contact = $conversation->contact;
        $state = $this->getState($conversation);
        $transcript = $this->buildTranscript($conversation, (int) $settings['max_messages']);
        $currentProduct = $this->loadProductById($state['current_product_id']);

        $lines = [
            'Канал: ' . ($contact?->platform === 'instagram' ? 'Instagram' : 'Messenger'),
            'Клієнт: ' . $this->chatService->resolveDisplayName($contact, $customer),
            'Телефон у CRM: ' . ($customer?->phone ?: 'немає'),
            'Email у CRM: ' . ($customer?->email ?: 'немає'),
            'Поточний етап: ' . ($conversation->stage?->name ?: 'Без етапу'),
            'Поточний AI summary: ' . ($state['summary'] ?: 'немає'),
        ];

        if ($currentProduct) {
            $lines[] = 'Поточний товар у діалозі: ' . $currentProduct->title;
            $lines[] = 'Ціна поточного товару: ' . $this->formatProductPrice($currentProduct);
            $lines[] = 'Доступні розміри: ' . ($this->formatAvailableSizes($currentProduct) ?: 'немає даних');
            $lines[] = 'Поточний вибраний розмір: ' . ($state['current_size'] ?: 'не вибрано');
        }

        return implode("\n", [
            ...$lines,
            'Останнє вхідне повідомлення: ' . $this->formatLatestInbound($message),
            'Останні повідомлення:',
            ...$transcript,
        ]);
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
            ],
            'required' => [
                'should_reply',
                'reply_text',
                'handoff_required',
                'handoff_reason',
                'summary',
                'lead_status',
                'collected_data',
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

        $message = null;

        if ($replyText !== '') {
            $message = $this->dispatchAiMessage(
                $conversation,
                $customer,
                $contact->platform,
                $contact->external_user_id,
                $replyText,
                [],
                $handoffRequired,
                $leadStatus,
                $model
            );
            $conversation = $this->chatService->updateConversationAfterMessage($conversation, $message, false);
        }

        foreach ($attachments as $attachment) {
            $attachmentMessage = $this->dispatchAiMessage(
                $conversation,
                $customer,
                $contact->platform,
                $contact->external_user_id,
                '',
                [$attachment],
                $handoffRequired,
                $leadStatus,
                $model
            );
            $conversation = $this->chatService->updateConversationAfterMessage($conversation, $attachmentMessage, false);
            $message = $attachmentMessage;
        }

        if ($handoffRequired) {
            $this->moveConversationToStage($conversation, 'new');
        }

        return $message;
    }

    private function dispatchAiMessage(
        ChatConversation $conversation,
        \App\Models\Customer $customer,
        string $platform,
        ?string $recipientId,
        string $text,
        array $attachments,
        bool $handoffRequired,
        string $leadStatus,
        string $model
    ): ChatMessage {
        $metaResult = $this->metaService->sendMessage(
            $customer,
            $text,
            $attachments,
            $platform,
            $recipientId
        );

        if (!$metaResult) {
            throw new RuntimeException('Meta API не прийняв AI-повідомлення.');
        }

        return $this->chatService->storeMessage($conversation, [
            'direction' => 'outbound',
            'external_message_id' => $metaResult['message_id'] ?? null,
            'delivery_status' => 'sent',
            'source' => 'system',
            'text' => $text !== '' ? $text : null,
            'sent_at' => now(config('app.timezone', 'Europe/Kyiv')),
            'meta' => [
                'ai_generated' => true,
                'provider' => 'openai',
                'model' => $model,
                'handoff_required' => $handoffRequired,
                'lead_status' => $leadStatus,
            ],
        ], $attachments);
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
        $text = preg_replace('/\s+/u', ' ', trim($text));

        return $this->limitText((string) $text, 600);
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

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null
     */
    private function resolveCatalogReply(ChatConversation $conversation, ChatMessage $message, array $state): ?array
    {
        $text = trim((string) $message->text);
        if ($text === '') {
            return null;
        }

        $selectedCandidate = $this->resolveSelectedCandidateProduct($text, $state);
        if ($selectedCandidate) {
            return $this->buildProductSelectionReply($selectedCandidate, $text, $state);
        }

        $candidates = $this->findProductCandidatesFromText($text);
        $matchedProduct = $this->selectSingleProductMatch($candidates);
        $currentProduct = $matchedProduct
            ?: $this->loadProductById((int) ($state['current_product_id'] ?? 0))
            ?: $this->matchProductFromText((string) ($state['lead']['product_interest'] ?? ''));

        if ($matchedProduct) {
            return $this->buildProductSelectionReply($matchedProduct, $text, $state);
        }

        if ($currentProduct && $this->looksLikeProductFollowUp($text)) {
            return $this->buildCurrentProductReply($currentProduct, $text, $state);
        }

        if ($candidates->isNotEmpty()) {
            return $this->buildProductOptionsReply($candidates, $state);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $currentState
     * @param  array<string, mixed>  $catalogReply
     */
    private function finalizeCatalogReply(
        ChatConversation $conversation,
        ChatMessage $message,
        array $currentState,
        array $catalogReply,
        string $model
    ): void {
        $sentMessage = null;
        $handoffRequired = (bool) ($catalogReply['handoff_required'] ?? false);
        $handoffReason = (string) ($catalogReply['handoff_reason'] ?? '');

        try {
            $sentMessage = $this->sendAiReply(
                $conversation,
                (string) ($catalogReply['reply_text'] ?? ''),
                $handoffRequired,
                (string) ($catalogReply['lead_status'] ?? 'qualifying'),
                $model,
                $catalogReply['attachments'] ?? []
            );
        } catch (\Throwable $e) {
            Log::warning('Chat AI catalog send failed', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            $handoffRequired = true;
            $handoffReason = $handoffReason !== '' ? $handoffReason : 'Не вдалося відправити відповідь по товару, потрібен менеджер.';
        }

        $status = $handoffRequired
            ? 'handoff'
            : ($sentMessage ? 'replied' : 'idle');

        $this->storeState($conversation, [
            'status' => $status,
            'last_inbound_message_id' => $message->id,
            'last_processed_message_id' => $message->id,
            'last_reply_message_id' => $sentMessage?->id,
            'last_ai_response_at' => $sentMessage ? now()->toDateTimeString() : ($currentState['last_ai_response_at'] ?? ''),
            'handoff_required' => $handoffRequired,
            'handoff_reason' => $handoffRequired ? $handoffReason : '',
            'summary' => (string) ($catalogReply['summary'] ?? $currentState['summary']),
            'lead_status' => (string) ($catalogReply['lead_status'] ?? 'qualifying'),
            'lead' => $catalogReply['lead'] ?? $currentState['lead'],
            'last_error' => '',
            'model' => $model,
            'updated_at' => now()->toDateTimeString(),
            'current_product_id' => $catalogReply['current_product_id'] ?? $currentState['current_product_id'],
            'current_product_title' => (string) ($catalogReply['current_product_title'] ?? $currentState['current_product_title']),
            'current_size' => (string) ($catalogReply['current_size'] ?? $currentState['current_size']),
            'candidate_product_ids' => $catalogReply['candidate_product_ids'] ?? [],
            'candidate_product_titles' => $catalogReply['candidate_product_titles'] ?? [],
        ]);

        if ($handoffRequired) {
            $this->moveConversationToStage($conversation, 'new');
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function buildProductSelectionReply(Product $product, string $text, array $state): array
    {
        $requestedSize = $this->extractRequestedSize($text);
        $asksPhoto = $this->messageAsksPhoto($text);
        $asksSizes = $this->messageAsksSizes($text);
        $attachments = $this->resolveProductPhotoAttachment($product);
        $price = $this->formatProductPrice($product);
        $sizes = $this->formatAvailableSizes($product);
        $sizePrompt = $this->buildSizePrompt($product);
        $variant = $requestedSize ? $this->resolveVariantForSize($product, $requestedSize) : null;

        if ($requestedSize !== null) {
            if ($variant) {
                $replyText = $this->buildVariantReply($product, $variant, $requestedSize, true);
            } else {
                $replyText = 'На жаль, ' . $requestedSize . ' розміру зараз не бачу. '
                    . ($sizes !== '' ? 'Доступні розміри: ' . $sizes . '. ' : '')
                    . 'Ціна цієї моделі ' . $price . '.';
            }
        } elseif ($asksSizes && $sizes !== '') {
            $replyText = 'Для моделі "' . $product->title . '" доступні розміри: ' . $sizes . '. Ціна ' . $price . '.';
        } else {
            $replyText = 'Ціна моделі "' . $product->title . '" ' . $price . '.';
            if ($sizes !== '') {
                $replyText .= ' Доступні розміри: ' . $sizes . '.';
            }
            if ($sizePrompt !== '') {
                $replyText .= ' ' . $sizePrompt;
            }
        }

        return [
            'reply_text' => $this->sanitizeReplyText($replyText),
            'attachments' => ($attachments !== [] && ($asksPhoto || $requestedSize !== null || !$this->messageAsksPrice($text)))
                ? $attachments
                : $attachments,
            'summary' => 'Товар: ' . $product->title . ' | ціна: ' . $price . ($requestedSize ? ' | розмір: ' . $requestedSize : ''),
            'lead_status' => 'qualifying',
            'lead' => array_merge($state['lead'] ?? [], [
                'product_interest' => $product->title,
                'notes' => $requestedSize ? ('Клієнт цікавиться розміром ' . $requestedSize) : ($state['lead']['notes'] ?? ''),
            ]),
            'current_product_id' => $product->id,
            'current_product_title' => $product->title,
            'current_size' => $requestedSize ?: '',
            'candidate_product_ids' => [],
            'candidate_product_titles' => [],
            'handoff_required' => false,
            'handoff_reason' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function buildCurrentProductReply(Product $product, string $text, array $state): array
    {
        $mentionedSize = $this->extractRequestedSize($text);
        $asksPhoto = $this->messageAsksPhoto($text);
        $asksSizes = $this->messageAsksSizes($text);
        $asksPrice = $this->messageAsksPrice($text);
        $asksAvailability = $this->messageAsksAvailability($text);
        $requestedSize = $mentionedSize ?: (($asksPrice || $asksAvailability) ? ($state['current_size'] ?: null) : null);
        $attachments = $asksPhoto ? $this->resolveProductPhotoAttachment($product) : [];
        $price = $this->formatProductPrice($product);
        $sizes = $this->formatAvailableSizes($product);
        $replyText = '';

        if ($asksSizes && $sizes !== '') {
            $replyText = 'Для моделі "' . $product->title . '" доступні розміри: ' . $sizes . '.';
            if ($asksPrice) {
                $replyText .= ' Ціна ' . $price . '.';
            }
        } elseif ($requestedSize !== null) {
            $variant = $this->resolveVariantForSize($product, $requestedSize);
            if ($variant) {
                $replyText = $this->buildVariantReply($product, $variant, $requestedSize, false);
            } else {
                $replyText = 'По моделі "' . $product->title . '" не бачу ' . $requestedSize . ' розміру.';
                if ($sizes !== '') {
                    $replyText .= ' Доступні розміри: ' . $sizes . '.';
                }
                $replyText .= ' Ціна моделі ' . $price . '.';
            }
        } elseif ($asksPrice) {
            $replyText = 'Ціна моделі "' . $product->title . '" ' . $price . '.';
        } elseif ($asksPhoto) {
            $replyText = 'Надсилаю фото моделі "' . $product->title . '". Ціна ' . $price . '.';
        } else {
            $replyText = 'По моделі "' . $product->title . '" ціна ' . $price . '.';
            if ($sizes !== '') {
                $replyText .= ' Доступні розміри: ' . $sizes . '.';
            }
        }

        return [
            'reply_text' => $this->sanitizeReplyText($replyText),
            'attachments' => $attachments,
            'summary' => 'Товар: ' . $product->title . ' | ціна: ' . $price . ($requestedSize ? ' | розмір: ' . $requestedSize : ''),
            'lead_status' => 'qualifying',
            'lead' => array_merge($state['lead'] ?? [], [
                'product_interest' => $product->title,
                'notes' => $requestedSize ? ('Клієнт питає про розмір ' . $requestedSize) : ($state['lead']['notes'] ?? ''),
            ]),
            'current_product_id' => $product->id,
            'current_product_title' => $product->title,
            'current_size' => $requestedSize ?: ($state['current_size'] ?? ''),
            'candidate_product_ids' => [],
            'candidate_product_titles' => [],
            'handoff_required' => false,
            'handoff_reason' => '',
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array{product: Product, score: int}>  $candidates
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function buildProductOptionsReply($candidates, array $state): array
    {
        $products = $candidates
            ->pluck('product')
            ->take(3)
            ->values();

        $lines = $products
            ->map(function (Product $product, int $index) {
                return ($index + 1) . '. "' . $product->title . '" — ' . $this->formatProductPrice($product);
            })
            ->implode(' ');

        return [
            'reply_text' => $this->sanitizeReplyText(
                'Знайшла кілька варіантів. ' . $lines . ' Напишіть назву моделі або просто номер 1, 2 чи 3.'
            ),
            'attachments' => [],
            'summary' => 'Клієнту запропоновано кілька товарів на вибір.',
            'lead_status' => 'qualifying',
            'lead' => array_merge($state['lead'] ?? [], [
                'notes' => 'Клієнту запропоновано кілька варіантів товару.',
            ]),
            'current_product_id' => null,
            'current_product_title' => '',
            'current_size' => '',
            'candidate_product_ids' => $products->pluck('id')->all(),
            'candidate_product_titles' => $products->pluck('title')->all(),
            'handoff_required' => false,
            'handoff_reason' => '',
        ];
    }

    private function buildVariantReply(Product $product, ProductVariant $variant, string $requestedSize, bool $includePrompt): string
    {
        $price = $this->formatProductPrice($product);
        $inStock = (bool) $variant->is_active && (int) $variant->stock_qty > 0;
        $variantLabel = $this->formatVariantLabel($variant);

        $reply = $variantLabel !== ''
            ? 'На ' . $requestedSize . ' розмір підійде варіант ' . $variantLabel . '. Ціна ' . $price . '.'
            : 'На ' . $requestedSize . ' розмір ціна ' . $price . '.';
        $reply .= $inStock
            ? ' Є в наявності.'
            : ' Зараз цього розміру не бачу в наявності.';

        if ($includePrompt) {
            $reply .= ' Якщо хочете, можу підказати ще доступні розміри.';
        }

        return $reply;
    }

    private function looksLikeProductFollowUp(string $text): bool
    {
        return $this->extractRequestedSize($text) !== null
            || $this->messageAsksPrice($text)
            || $this->messageAsksAvailability($text)
            || $this->messageAsksPhoto($text)
            || $this->messageAsksSizes($text);
    }

    private function messageAsksPrice(string $text): bool
    {
        $normalized = mb_strtolower($text);

        return str_contains($normalized, 'ціна')
            || str_contains($normalized, 'скільки')
            || str_contains($normalized, 'варт')
            || str_contains($normalized, 'грн');
    }

    private function messageAsksAvailability(string $text): bool
    {
        $normalized = mb_strtolower($text);

        return preg_match('/\bє\b/u', $normalized) === 1
            || str_contains($normalized, 'в наяв')
            || str_contains($normalized, 'наяв')
            || str_contains($normalized, 'доступ');
    }

    private function messageAsksPhoto(string $text): bool
    {
        $normalized = mb_strtolower($text);

        return str_contains($normalized, 'фото')
            || str_contains($normalized, 'покажи')
            || str_contains($normalized, 'скинь');
    }

    private function messageAsksSizes(string $text): bool
    {
        $normalized = mb_strtolower($text);

        return str_contains($normalized, 'розмір')
            || str_contains($normalized, 'розміри');
    }

    private function extractRequestedSize(string $text): ?string
    {
        if (!preg_match('/\b(3[0-9]|4[0-9]|50)\b/u', $text, $matches)) {
            return null;
        }

        return $matches[1] ?? null;
    }

    private function loadProductById(?int $productId): ?Product
    {
        if (!$productId) {
            return null;
        }

        return Product::query()
            ->with([
                'variants' => fn ($query) => $query->orderBy('size'),
                'color',
                'category',
            ])
            ->find($productId);
    }

    private function matchProductFromText(string $text): ?Product
    {
        return $this->selectSingleProductMatch($this->findProductCandidatesFromText($text));
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{product: Product, score: int}>
     */
    private function findProductCandidatesFromText(string $text)
    {
        $normalizedText = $this->normalizeComparableText($text);
        $tokens = $this->extractSearchTokens($text);
        $fragments = $this->buildSearchFragments($tokens);

        if ($normalizedText === '' || $fragments === []) {
            return collect();
        }

        $products = Product::query()
            ->with([
                'variants' => fn ($query) => $query->orderBy('size'),
                'color',
                'category',
            ])
            ->where('is_active', true)
            ->where(function ($query) use ($fragments, $normalizedText) {
                foreach ($fragments as $fragment) {
                    $query
                        ->orWhere('title', 'like', '%' . $fragment . '%')
                        ->orWhere('description', 'like', '%' . $fragment . '%')
                        ->orWhere('sku', 'like', '%' . $fragment . '%')
                        ->orWhereHas('variants', function ($variantQuery) use ($fragment) {
                            $variantQuery->where('sku', 'like', '%' . $fragment . '%');
                        })
                        ->orWhereHas('color', function ($colorQuery) use ($fragment) {
                            $colorQuery->where('name', 'like', '%' . $fragment . '%');
                        })
                        ->orWhereHas('category', function ($categoryQuery) use ($fragment) {
                            $categoryQuery->where('name', 'like', '%' . $fragment . '%');
                        });
                }

                $query
                    ->orWhere('sku', 'like', '%' . $normalizedText . '%')
                    ->orWhere('title', 'like', '%' . $normalizedText . '%')
                    ->orWhereHas('color', function ($colorQuery) use ($normalizedText) {
                        $colorQuery->where('name', 'like', '%' . $normalizedText . '%');
                    })
                    ->orWhereHas('category', function ($categoryQuery) use ($normalizedText) {
                        $categoryQuery->where('name', 'like', '%' . $normalizedText . '%');
                    });
            })
            ->limit(24)
            ->get();

        if ($products->isEmpty()) {
            return collect();
        }

        return $products->map(function (Product $product) use ($normalizedText, $tokens) {
            return [
                'product' => $product,
                'score' => $this->scoreProductMatch($product, $normalizedText, $tokens),
            ];
        })->filter(fn (array $item) => $item['score'] >= 40)
            ->sortByDesc('score')
            ->values()
            ->take(3);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array{product: Product, score: int}>  $candidates
     */
    private function selectSingleProductMatch($candidates): ?Product
    {
        $top = $candidates->first();
        $second = $candidates->get(1);

        if (!$top || $top['score'] < 60) {
            return null;
        }

        if ($second && ($top['score'] - $second['score']) < 14 && $top['score'] < 110) {
            return null;
        }

        return $top['product'];
    }

    /**
     * @param  array<int, string>  $tokens
     */
    private function scoreProductMatch(Product $product, string $normalizedText, array $tokens): int
    {
        $title = $this->normalizeComparableText($product->title);
        $sku = $this->normalizeComparableText((string) $product->sku);
        $description = $this->normalizeComparableText((string) $product->description);
        $category = $this->normalizeComparableText((string) optional($product->category)->name);
        $color = $this->normalizeComparableText((string) optional($product->color)->name);
        $variantSkus = $product->variants
            ->pluck('sku')
            ->filter()
            ->map(fn ($value) => $this->normalizeComparableText((string) $value))
            ->all();
        $productLexemes = $this->buildComparableLexemes([
            $product->title,
            (string) $product->sku,
            (string) $product->description,
            (string) optional($product->category)->name,
            (string) optional($product->color)->name,
        ]);
        $queryLexemes = $this->buildComparableLexemes($tokens);
        $queryGroups = array_values(array_filter($queryLexemes, fn ($lexeme) => str_starts_with($lexeme, 'group:')));
        $productGroups = array_values(array_filter($productLexemes, fn ($lexeme) => str_starts_with($lexeme, 'group:')));

        $score = 0;

        if ($sku !== '' && $sku === $normalizedText) {
            $score += 220;
        }

        if ($title === $normalizedText) {
            $score += 200;
        }

        if ($title !== '' && str_contains($title, $normalizedText)) {
            $score += 90;
        }

        if ($category !== '' && str_contains($category, $normalizedText)) {
            $score += 70;
        }

        if ($color !== '' && str_contains($color, $normalizedText)) {
            $score += 70;
        }

        foreach ($tokens as $token) {
            if (str_contains($title, $token)) {
                $score += 28;
            }

            if ($sku !== '' && str_contains($sku, $token)) {
                $score += 24;
            }

            if ($description !== '' && str_contains($description, $token)) {
                $score += 10;
            }

            if ($category !== '' && str_contains($category, $token)) {
                $score += 16;
            }

            if ($color !== '' && str_contains($color, $token)) {
                $score += 20;
            }

            foreach ($variantSkus as $variantSku) {
                if ($variantSku !== '' && str_contains($variantSku, $token)) {
                    $score += 16;
                    break;
                }
            }
        }

        foreach ($queryLexemes as $lexeme) {
            if (!in_array($lexeme, $productLexemes, true)) {
                continue;
            }

            $score += str_starts_with($lexeme, 'group:')
                ? 34
                : 18;
        }

        $colorGroups = ['group:red', 'group:burgundy', 'group:black', 'group:white', 'group:gray', 'group:pink', 'group:blue', 'group:beige', 'group:brown'];

        foreach ($queryGroups as $group) {
            if (in_array($group, $productGroups, true)) {
                continue;
            }

            $score -= in_array($group, $colorGroups, true)
                ? 70
                : 22;
        }

        return $score;
    }

    /**
     * @return array<int, string>
     */
    private function extractSearchTokens(string $text): array
    {
        $normalized = $this->normalizeComparableText($text);
        $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stopWords = [
            'ціна', 'скільки', 'скількикоштує', 'грн', 'розмір', 'розміри', 'є', 'наявності', 'наявність',
            'фото', 'покажи', 'скинь', 'будь', 'ласка', 'будьласка', 'мені', 'треба', 'хочу', 'на',
            'цей', 'ця', 'це', 'по', 'для', 'чи', 'а', 'і', 'та', 'у', 'в', 'про',
        ];

        return array_values(array_unique(array_filter($tokens, function ($token) use ($stopWords) {
            if (in_array($token, $stopWords, true)) {
                return false;
            }

            if (preg_match('/^\d+$/', $token)) {
                return false;
            }

            return mb_strlen($token) >= 3;
        })));
    }

    /**
     * @param  array<int, string>  $tokens
     * @return array<int, string>
     */
    private function buildSearchFragments(array $tokens): array
    {
        $fragments = [];

        foreach ($tokens as $token) {
            $fragments[] = $token;

            $stem = $this->stemComparableToken($token);
            if ($stem !== '' && mb_strlen($stem) >= 3) {
                $fragments[] = $stem;
            }

            foreach ($this->detectComparableGroups($token) as $group) {
                foreach ($this->comparableTokenGroups()[$group] ?? [] as $alias) {
                    if (mb_strlen($alias) >= 3) {
                        $fragments[] = $alias;
                    }
                }
            }
        }

        return array_values(array_unique($fragments));
    }

    /**
     * @param  array<int, string>  $parts
     * @return array<int, string>
     */
    private function buildComparableLexemes(array $parts): array
    {
        $lexemes = [];

        foreach ($parts as $part) {
            $normalized = $this->normalizeComparableText((string) $part);
            if ($normalized === '') {
                continue;
            }

            foreach (preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                if (mb_strlen($token) < 2) {
                    continue;
                }

                $lexemes[] = $token;

                $stem = $this->stemComparableToken($token);
                if ($stem !== '' && $stem !== $token) {
                    $lexemes[] = $stem;
                }

                foreach ($this->detectComparableGroups($token) as $group) {
                    $lexemes[] = 'group:' . $group;
                }
            }
        }

        return array_values(array_unique($lexemes));
    }

    /**
     * @return array<int, string>
     */
    private function detectComparableGroups(string $token): array
    {
        $normalized = $this->normalizeComparableText($token);
        $groups = [];

        foreach ($this->comparableTokenGroups() as $group => $aliases) {
            foreach ($aliases as $alias) {
                if (str_contains($normalized, $alias) || str_contains($alias, $normalized)) {
                    $groups[] = $group;
                    break;
                }
            }
        }

        return $groups;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function comparableTokenGroups(): array
    {
        return [
            'slippers' => ['тап', 'тапк', 'тапоч', 'тапул', 'капц', 'капчик'],
            'red' => ['червон', 'красн'],
            'burgundy' => ['бордов'],
            'black' => ['чорн', 'black'],
            'white' => ['біл', 'бiл', 'white'],
            'gray' => ['сір', 'сiр', 'сер'],
            'pink' => ['рож', 'pink'],
            'blue' => ['син', 'голуб', 'blue'],
            'beige' => ['беж', 'beige', 'крем'],
            'brown' => ['коричн', 'шоколад', 'brown'],
        ];
    }

    private function stemComparableToken(string $token): string
    {
        $token = $this->normalizeComparableText($token);
        $suffixes = [
            'ього', 'ого', 'ому', 'ими', 'ями', 'ами', 'ові', 'еві', 'ої', 'ий', 'ій', 'а', 'я',
            'е', 'и', 'і', 'у', 'ю', 'ом', 'ою', 'ею', 'ок', 'ки', 'ка', 'ці', 'ць', 'улі', 'уля',
            'ів', 'ев', 'ов',
        ];

        foreach ($suffixes as $suffix) {
            if (!str_ends_with($token, $suffix)) {
                continue;
            }

            $stem = mb_substr($token, 0, mb_strlen($token) - mb_strlen($suffix));
            if (mb_strlen($stem) >= 3) {
                return $stem;
            }
        }

        return $token;
    }

    private function normalizeComparableText(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', (string) $text));
    }

    private function extractCandidateSelectionIndex(string $text): ?int
    {
        if (!preg_match('/^\s*([1-3])\s*$/u', $text, $matches)) {
            return null;
        }

        return max(0, ((int) ($matches[1] ?? 0)) - 1);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function resolveSelectedCandidateProduct(string $text, array $state): ?Product
    {
        $index = $this->extractCandidateSelectionIndex($text);
        if ($index === null) {
            return null;
        }

        $candidateIds = array_values(array_filter(
            $state['candidate_product_ids'] ?? [],
            fn ($value) => (int) $value > 0
        ));

        $productId = $candidateIds[$index] ?? null;

        return $productId ? $this->loadProductById((int) $productId) : null;
    }

    private function resolveVariantForSize(Product $product, string $requestedSize): ?ProductVariant
    {
        return $product->variants
            ->first(function (ProductVariant $variant) use ($requestedSize) {
                $size = trim((string) $variant->size);
                if ($size === '') {
                    return false;
                }

                if ($size === $requestedSize) {
                    return true;
                }

                $parts = preg_split('/[^\d]+/u', $size, -1, PREG_SPLIT_NO_EMPTY) ?: [];

                return in_array($requestedSize, $parts, true);
            });
    }

    private function formatAvailableSizes(Product $product): string
    {
        $sizes = $product->variants
            ->filter(fn (ProductVariant $variant) => $variant->is_active && (int) $variant->stock_qty > 0)
            ->map(fn (ProductVariant $variant) => $this->formatVariantLabel($variant))
            ->filter()
            ->unique()
            ->values();

        if ($sizes->isEmpty()) {
            $sizes = $product->variants
                ->map(fn (ProductVariant $variant) => $this->formatVariantLabel($variant))
                ->filter()
                ->unique()
                ->values();
        }

        return $sizes->implode(', ');
    }

    private function formatVariantLabel(ProductVariant $variant): string
    {
        $raw = trim((string) $variant->size);
        if ($raw === '') {
            return '';
        }

        if (preg_match('/^(.+?)\s+-\s+(.+)$/u', $raw, $matches) === 1) {
            $primary = trim((string) ($matches[1] ?? ''));
            $secondary = trim((string) ($matches[2] ?? ''));

            if ($primary !== '' && $secondary !== '' && preg_match('/см|cm/u', $secondary) === 1) {
                return $primary . ' (' . $secondary . ')';
            }

            if ($primary !== '') {
                return $primary;
            }
        }

        return $raw;
    }

    private function buildSizePrompt(Product $product): string
    {
        $firstVariant = $product->variants
            ->filter(fn (ProductVariant $variant) => trim((string) $variant->size) !== '')
            ->first();

        if (!$firstVariant) {
            return '';
        }

        $variantLabel = $this->formatVariantLabel($firstVariant);
        if ($variantLabel === '') {
            return '';
        }

        return 'Напишіть свій розмір, і я підкажу, який варіант підійде.';
    }

    private function formatProductPrice(Product $product): string
    {
        if ($product->sale_price === null) {
            return 'уточнює менеджер';
        }

        $amount = rtrim(rtrim(number_format((float) $product->sale_price, 2, '.', ' '), '0'), '.');
        $currency = strtoupper((string) ($product->currency ?: 'UAH'));
        $suffix = match ($currency) {
            'UAH' => 'грн',
            default => $currency,
        };

        return trim($amount . ' ' . $suffix);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function resolveProductPhotoAttachment(Product $product): array
    {
        $photoUrl = $this->resolveProductPhotoUrl($product);

        return $photoUrl ? [[
            'type' => 'image',
            'url' => $photoUrl,
        ]] : [];
    }

    private function resolveProductPhotoUrl(Product $product): ?string
    {
        $photoUrl = $product->main_photo_url;

        if (!$photoUrl) {
            return null;
        }

        return str_starts_with($photoUrl, 'http')
            ? $photoUrl
            : url(ltrim($photoUrl, '/'));
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null
     */
    private function resolveCurrentProductPayload(array $state): ?array
    {
        if (empty($state['current_product_id']) && empty($state['current_product_title'])) {
            return null;
        }

        return [
            'id' => $state['current_product_id'] ?: null,
            'title' => $state['current_product_title'] ?: null,
        ];
    }
}
