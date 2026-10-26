<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\Response;

/** Провайдер, ключ і модель фіксуються на весь хід, навіть якщо налаштування змінили. */
class AiSession
{
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        private string $key,
        private ClaudeClient|OpenAiClient $client,
    ) {}

    public function hasKey(): bool
    {
        return trim($this->key) !== '';
    }

    public function messages(array $payload, int $timeout = 40): Response
    {
        return $this->client->messages($this->key, ['model' => $this->model] + $payload, $timeout);
    }
}
