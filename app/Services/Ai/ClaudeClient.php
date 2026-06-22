<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Тонкий клієнт Messages API Anthropic: лише HTTP-виклик (URL, заголовки,
 * версія). Розбір відповіді лишається у викликачів. Винесено з AiAgentService,
 * щоб не дублювати запит у respond() і summarizeConversation().
 */
class ClaudeClient
{
    public function messages(string $apiKey, array $payload, int $timeout = 40): Response
    {
        return Http::timeout($timeout)->withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
        ])->post('https://api.anthropic.com/v1/messages', $payload);
    }
}
