<?php

namespace App\Services\Ai\Channels;

use App\Models\AiChannelSetting;
use App\Models\AiSetting;
use App\Models\InboxConversation;

class ProviderPolicy
{
    public function selected(?InboxConversation $conversation = null): string
    {
        return $conversation?->ai_provider ?: AiChannelSetting::current()->default_provider;
    }

    public function enabled(string $provider): bool
    {
        $settings = AiChannelSetting::current();
        return match ($provider) { 'claude' => $settings->claude_enabled, 'gpt' => $settings->gpt_enabled, default => false };
    }

    public function ready(string $provider): bool
    {
        if (! $this->enabled($provider)) return false;
        if ($provider === 'claude') return (bool) AiSetting::query()->whereNull('meta_connection_id')->value('api_key');
        $settings = AiChannelSetting::current();
        return (bool) $settings->gpt_api_key && trim((string) $settings->gpt_model) !== '';
    }

    public function assertEnabled(string $provider): void
    {
        if (! $this->enabled($provider)) {
            throw new ProviderUnavailable(($provider === 'claude' ? 'Claude' : 'GPT').' API вимкнено в налаштуваннях AI-агента.');
        }
        app(DispatchContext::class)->assertCurrent($provider);
    }
}
