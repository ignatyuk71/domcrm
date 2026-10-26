<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use InvalidArgumentException;

/** Вибір провайдера без зміни бізнес-логіки агента та наявного підключення Claude. */
class AiClient
{
    public function __construct(private ClaudeClient $claude, private OpenAiClient $openai) {}

    public function forSettings(AiSetting $global): AiSession
    {
        $provider = $global->provider ?: 'claude';
        if (! in_array($provider, ['claude', 'openai'], true)) {
            throw new InvalidArgumentException('Невідомий провайдер ШІ.');
        }

        return new AiSession(
            $provider,
            $provider === 'openai' ? ($global->openai_model ?: AiSetting::DEFAULT_OPENAI_MODEL) : ($global->model ?: 'claude-sonnet-4-6'),
            (string) ($provider === 'openai' ? $global->openai_api_key : $global->api_key),
            $provider === 'openai' ? $this->openai : $this->claude,
        );
    }
}
