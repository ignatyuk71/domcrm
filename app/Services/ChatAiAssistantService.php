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
        $knowledgeContext = $this->resolveKnowledgeContext($message);

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

        $replyText = $this->sanitizeReplyText((string) ($decision['reply_text'] ?? ''));
        $handoffRequired = (bool) ($decision['handoff_required'] ?? false);
        $shouldReply = (bool) ($decision['should_reply'] ?? false) && $replyText !== '';
        $replyAttachments = $this->resolveReplyAttachments(
            $decision['attachment_urls'] ?? [],
            $knowledgeContext,
            $message
        );
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
            $instructions[] = 'Якщо тема не визначена, спочатку уточни тип товару одним коротким питанням.';
        }

        $instructions[] = 'Для фото використовуй тільки URL з релевантного медіа-контексту.';
        $instructions[] = 'Для ціни та розмірів використовуй тільки релевантні товари з контексту.';
        $instructions[] = 'Коли клієнт просить показати/надіслати фото, заповнюй attachment_urls релевантними URL тільки з цього контексту.';
        $instructions[] = 'Якщо фото не запитували або URL немає в контексті, поверни attachment_urls як порожній масив.';
        $instructions[] = 'Не нумеруй товари або медіа у відповіді клієнту і не проси вибрати номер.';

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
                foreach ($knowledgeContext['products'] as $product) {
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

                    $input[] = '- ' . implode('; ', $parts);
                }
            }

            if (!empty($knowledgeContext['media'])) {
                $input[] = 'Релевантні медіа теми (можна давати URL клієнту):';
                foreach ($knowledgeContext['media'] as $media) {
                    $input[] = '- '
                        . $media['label'] . ' | '
                        . $media['media_type'] . ' | '
                        . $media['url'];
                }
            }
        } else {
            $input[] = 'Тема не визначена. Якщо запит нечіткий, уточни тип товару.';
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
        if (!$photoRequested) {
            return [];
        }

        $selected = [];
        foreach ((array) $attachmentUrls as $rawUrl) {
            if (!is_string($rawUrl)) {
                continue;
            }

            $normalizedUrl = $this->normalizeAttachmentUrl($rawUrl);
            if ($normalizedUrl === '' || !isset($allowedMap[$normalizedUrl])) {
                continue;
            }

            $selected[$normalizedUrl] = $normalizedUrl;
        }

        if ($selected === []) {
            foreach ($this->fallbackAttachmentCandidates($message, $knowledgeContext) as $candidateUrl) {
                $normalizedUrl = $this->normalizeAttachmentUrl($candidateUrl);
                if ($normalizedUrl === '' || !isset($allowedMap[$normalizedUrl])) {
                    continue;
                }

                $selected[$normalizedUrl] = $normalizedUrl;
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

        foreach ((array) ($knowledgeContext['products'] ?? []) as $product) {
            $url = $this->normalizeAttachmentUrl((string) ($product['photo_url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $map[$url] = $url;
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
    private function resolveKnowledgeContext(ChatMessage $message): array
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

        if ($topics->isEmpty()) {
            return [
                'selected_topic' => null,
                'matched_positive' => [],
                'products' => [],
                'media' => [],
                'rules' => $rules,
            ];
        }

        $matchSource = $this->normalizeForMatch($this->extractMessageForMatching($message));
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

        $selected = $scored->first(fn (array $row) => $row['score'] > 0);
        if ($selected === null && $topics->count() === 1) {
            $selected = [
                'topic' => $topics->first(),
                'score' => 0,
                'positive' => [],
            ];
        }

        if ($selected === null) {
            return [
                'selected_topic' => null,
                'matched_positive' => [],
                'products' => [],
                'media' => [],
                'rules' => $rules,
            ];
        }

        /** @var ChatAiTopic $selectedTopic */
        $selectedTopic = $selected['topic'];

        return [
            'selected_topic' => [
                'id' => $selectedTopic->id,
                'name' => (string) $selectedTopic->name,
                'instruction' => (string) ($selectedTopic->instruction ?? ''),
            ],
            'matched_positive' => array_slice($selected['positive'], 0, 6),
            'products' => $this->topicProductsPayload($selectedTopic),
            'media' => $this->topicMediaPayload($selectedTopic),
            'rules' => $rules,
        ];
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
