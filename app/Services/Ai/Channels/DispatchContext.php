<?php

namespace App\Services\Ai\Channels;

use App\Models\InboxConversation;

/** Контекст лише поточної операції агента; ручні повідомлення ним не обмежуються. */
class DispatchContext
{
    private ?array $operation = null;

    public function run(string $provider, ?InboxConversation $conversation, callable $action, ?int $triggerId = null): mixed
    {
        $before = $this->operation;
        $this->operation = ['provider' => $provider, 'conversation_id' => $conversation?->id, 'version' => (int) $conversation?->ai_provider_version, 'trigger_id' => $triggerId];
        try { return $action(); } finally { $this->operation = $before; }
    }

    public function assertCurrent(?string $provider = null): void
    {
        if (! $this->operation) return;
        $operation = $this->operation;
        if ($provider && $operation['provider'] !== $provider) throw new ProviderUnavailable('Запит до неактивного API заблоковано.');
        $policy = app(ProviderPolicy::class);
        if (! $policy->enabled($operation['provider'])) throw new ProviderUnavailable('API агента вимкнено під час обробки.');
        $conversation = $operation['conversation_id'] ? InboxConversation::find($operation['conversation_id']) : null;
        if ($conversation && $operation['trigger_id'] && $conversation->messages()->where('id', '>', $operation['trigger_id'])->where('sender', '!=', 'ai')->exists()) {
            throw new ProviderUnavailable('Нове повідомлення змінило контекст розмови.');
        }
        if (($operation['conversation_id'] && (! $conversation || $conversation->ai_provider_version !== $operation['version']))
            || $policy->selected() !== $operation['provider']) {
            throw new ProviderUnavailable('Агента або стан розмови змінено; застаріле відправлення заблоковано.');
        }
    }

    public function textSent(string $text): void
    {
        if (! ($this->operation['conversation_id'] ?? null)) return;
        $delivery = \App\Services\Ai\OrderTexts::all()['delivery_template'];
        $final = \App\Services\Ai\OrderTexts::all()['final_message'];
        if ($text === $delivery) InboxConversation::whereKey($this->operation['conversation_id'])->update(['ai_checkout_active' => true]);
        if (str_contains($text, $final)) InboxConversation::whereKey($this->operation['conversation_id'])->update(['ai_checkout_active' => false]);
    }
}
