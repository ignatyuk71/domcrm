<?php

namespace App\Services;

use App\Models\ChatAiSetting;

class ChatAiSettingsService
{
    /**
     * @return array{
     *   enabled: bool,
     *   default_agent_code: string,
     *   reply_delay_seconds: int,
     *   allow_assigned_conversations: bool,
     *   max_messages: int
     * }
     */
    public function get(): array
    {
        $defaults = $this->defaults();
        $record = ChatAiSetting::query()->latest('id')->first();

        if (!$record) {
            return $defaults;
        }

        return [
            'enabled' => (bool) $record->enabled,
            'default_agent_code' => (string) ($record->default_agent_code ?: $defaults['default_agent_code']),
            'reply_delay_seconds' => $this->normalizeDelaySeconds($record->reply_delay_seconds),
            'allow_assigned_conversations' => (bool) $record->allow_assigned_conversations,
            'max_messages' => $this->normalizeMaxMessages($record->max_messages),
        ];
    }

    public function save(array $payload, ?int $userId = null): array
    {
        $record = ChatAiSetting::query()->latest('id')->first();
        if (!$record) {
            $record = new ChatAiSetting();
        }

        $defaults = $this->defaults();

        $record->enabled = array_key_exists('enabled', $payload)
            ? (bool) $payload['enabled']
            : $defaults['enabled'];
        $record->default_agent_code = trim((string) ($payload['default_agent_code'] ?? '')) ?: $defaults['default_agent_code'];
        $record->reply_delay_seconds = $this->normalizeDelaySeconds($payload['reply_delay_seconds'] ?? $defaults['reply_delay_seconds']);
        $record->allow_assigned_conversations = array_key_exists('allow_assigned_conversations', $payload)
            ? (bool) $payload['allow_assigned_conversations']
            : $defaults['allow_assigned_conversations'];
        $record->max_messages = $this->normalizeMaxMessages($payload['max_messages'] ?? $defaults['max_messages']);
        $record->updated_by = $userId;
        $record->save();

        return $this->get();
    }

    private function defaults(): array
    {
        return [
            'enabled' => (bool) config('services.chat_ai.enabled', true),
            'default_agent_code' => (string) config('services.chat_ai.default_agent_code', 'sales_assistant_v1'),
            'reply_delay_seconds' => $this->normalizeDelaySeconds((int) config('services.chat_ai.reply_delay_seconds', 12)),
            'allow_assigned_conversations' => (bool) config('services.chat_ai.allow_assigned_conversations', true),
            'max_messages' => $this->normalizeMaxMessages((int) config('services.chat_ai.max_messages', 12)),
        ];
    }

    private function normalizeDelaySeconds(mixed $value): int
    {
        $seconds = is_numeric($value) ? (int) $value : 12;

        return min(60, max(5, $seconds));
    }

    private function normalizeMaxMessages(mixed $value): int
    {
        $maxMessages = is_numeric($value) ? (int) $value : 12;

        return min(30, max(4, $maxMessages));
    }
}
