<?php

namespace App\Services\Ai\Channels;

use App\Models\AiChannelSetting;

class ProviderPolicy
{
    public function selected(): string
    {
        return AiChannelSetting::current()->default_provider;
    }

    public function enabled(string $provider): bool
    {
        $settings = AiChannelSetting::current();
        return match ($provider) { 'claude' => $settings->claude_enabled, 'gpt' => $settings->gpt_enabled, default => false };
    }

    public function assertEnabled(string $provider): void
    {
        if (! $this->enabled($provider)) {
            throw new ProviderUnavailable(($provider === 'claude' ? 'Claude' : 'GPT').' API вимкнено в налаштуваннях AI-агента.');
        }
        app(DispatchContext::class)->assertCurrent($provider);
    }
}
