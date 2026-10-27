<?php

namespace App\Modules\Gpt\Infrastructure;

use App\Modules\Gpt\Domain\DraftFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ResponsesClient
{
    public function request(string $key, array $payload, int $remainingSeconds): array
    {
        try {
            $response = Http::withToken($key)->acceptJson()->asJson()->connectTimeout(5)
                ->timeout(max(1, min((int) config('gpt-drafts.timeout'), $remainingSeconds)))
                ->post('https://api.openai.com/v1/responses', $payload);
        } catch (ConnectionException) {
            throw new DraftFailure('connection', 'OpenAI не відповів вчасно. Повідомлення збережено; можна повторити створення чернетки.');
        }
        // Тіла помилок провайдера не потрапляють у журнал або відповідь користувачу.
        if (! $response->successful()) {
            throw new DraftFailure('provider_'.$response->status(), match ($response->status()) {
                401, 403 => 'OpenAI відхилив доступ. Перевірте окремий API-ключ GPT.',
                429 => 'Досягнуто ліміту OpenAI. Перевірте квоту та повторіть пізніше.',
                default => 'OpenAI не зміг створити чернетку. Перевірте модель і повторіть спробу.',
            });
        }
        $data = $response->json();
        if (! is_array($data) || ($data['status'] ?? null) !== 'completed' || ! is_array($data['output'] ?? null)) {
            throw new DraftFailure('incomplete', 'OpenAI повернув незавершену відповідь. Чернетку не прийнято.');
        }

        return $data;
    }
}
