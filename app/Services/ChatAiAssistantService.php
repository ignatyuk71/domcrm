<?php

namespace App\Services;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatStage;
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

        return implode("\n", [
            'Канал: ' . ($contact?->platform === 'instagram' ? 'Instagram' : 'Messenger'),
            'Клієнт: ' . $this->chatService->resolveDisplayName($contact, $customer),
            'Телефон у CRM: ' . ($customer?->phone ?: 'немає'),
            'Email у CRM: ' . ($customer?->email ?: 'немає'),
            'Поточний етап: ' . ($conversation->stage?->name ?: 'Без етапу'),
            'Поточний AI summary: ' . ($state['summary'] ?: 'немає'),
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
        string $model
    ): ?ChatMessage {
        $customer = $conversation->customer;
        $contact = $conversation->contact;

        if (!$customer || !$contact) {
            throw new RuntimeException('Для AI-відповіді не вистачає customer/contact.');
        }

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

        $message = $this->chatService->storeMessage($conversation, [
            'direction' => 'outbound',
            'external_message_id' => $metaResult['message_id'] ?? null,
            'delivery_status' => 'sent',
            'source' => 'system',
            'text' => $replyText,
            'sent_at' => now(config('app.timezone', 'Europe/Kyiv')),
            'meta' => [
                'ai_generated' => true,
                'provider' => 'openai',
                'model' => $model,
                'handoff_required' => $handoffRequired,
                'lead_status' => $leadStatus,
            ],
        ]);

        $conversation = $this->chatService->updateConversationAfterMessage($conversation, $message, false);

        if ($handoffRequired) {
            $this->moveConversationToStage($conversation, 'new');
        }

        return $message;
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
}
