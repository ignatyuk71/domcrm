<?php

namespace App\Services;

use App\Models\ChatAiSetting;
use App\Models\User;

class ChatAiSettingsService
{
    /**
     * @return array<string, mixed>
     */
    public function resolveRuntimeSettings(): array
    {
        $settings = $this->current();
        $defaults = $this->defaults();

        return [
            'enabled' => (bool) ($settings?->enabled ?? $defaults['enabled']),
            'assistant_name' => $this->stringOrDefault($settings?->assistant_name, $defaults['assistant_name']),
            'model' => $this->stringOrDefault($settings?->model, $defaults['model']),
            'max_messages' => $this->normalizeMaxMessages($settings?->max_messages ?? $defaults['max_messages']),
            'reply_style' => $this->stringOrDefault($settings?->reply_style, $defaults['reply_style']),
            'company_context' => trim((string) ($settings?->company_context ?? '')),
            'qualification_fields' => $this->normalizeQualificationFields(
                $settings?->qualification_fields ?? $defaults['qualification_fields']
            ),
            'handoff_rules' => trim((string) ($settings?->handoff_rules ?? $defaults['handoff_rules'])),
            'knowledge_base' => trim((string) ($settings?->knowledge_base ?? '')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $settings = $this->current();
        $runtime = $this->resolveRuntimeSettings();

        return [
            'settings' => [
                ...$runtime,
                'updated_at' => optional($settings?->updated_at)?->toIso8601String(),
            ],
            'meta' => [
                'has_api_key' => filled(config('services.openai.api_key')),
                'api_key_source' => '.env',
                'default_model' => (string) config('services.openai.model', 'gpt-4.1-mini'),
                'default_max_messages' => (int) config('services.chat_ai.max_messages', 12),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(array $data, ?User $user = null): ChatAiSetting
    {
        $settings = $this->current() ?? new ChatAiSetting();
        $settings->enabled = (bool) ($data['enabled'] ?? true);
        $settings->assistant_name = $this->nullableString($data['assistant_name'] ?? null);
        $settings->model = $this->nullableString($data['model'] ?? null);
        $settings->max_messages = $this->normalizeMaxMessages($data['max_messages'] ?? null);
        $settings->reply_style = $this->nullableString($data['reply_style'] ?? null);
        $settings->company_context = $this->nullableString($data['company_context'] ?? null);
        $settings->qualification_fields = $this->normalizeQualificationFields($data['qualification_fields'] ?? []);
        $settings->handoff_rules = $this->nullableString($data['handoff_rules'] ?? null);
        $settings->knowledge_base = $this->nullableString($data['knowledge_base'] ?? null);
        $settings->updated_by = $user?->id;
        $settings->save();

        return $settings->fresh();
    }

    public function current(): ?ChatAiSetting
    {
        try {
            return ChatAiSetting::current();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'enabled' => (bool) config('services.chat_ai.enabled', true),
            'assistant_name' => 'DomCRM AI',
            'model' => (string) config('services.openai.model', 'gpt-4.1-mini'),
            'max_messages' => (int) config('services.chat_ai.max_messages', 12),
            'reply_style' => 'Коротко, спокійно, по суті, без зайвих обіцянок.',
            'qualification_fields' => ['імʼя', 'телефон', 'товар', 'бюджет', 'термін', 'місто'],
            'handoff_rules' => "Точна ціна\nЗнижка\nОплата\nЖивий менеджер\nНестандартний запит\nСкарга або конфлікт",
        ];
    }

    /**
     * @param  array<int, mixed>  $fields
     * @return array<int, string>
     */
    private function normalizeQualificationFields(array $fields): array
    {
        $normalized = [];

        foreach ($fields as $field) {
            $value = trim((string) $field);
            if ($value === '') {
                continue;
            }

            $normalized[] = mb_substr($value, 0, 80);
        }

        $normalized = array_values(array_unique($normalized));

        if ($normalized === []) {
            return $this->defaults()['qualification_fields'];
        }

        return $normalized;
    }

    private function normalizeMaxMessages(mixed $value): int
    {
        $number = (int) $value;

        if ($number < 4) {
            return 4;
        }

        if ($number > 30) {
            return 30;
        }

        return $number;
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function stringOrDefault(mixed $value, string $default): string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : $default;
    }
}
