<?php

namespace App\Services\Ai\Gpt;

use App\Services\Ai\Gpt\GptFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class OpenAiClient
{
    private function request(#[\SensitiveParameter] string $key, int $timeout): PendingRequest
    {
        try {
            app(\App\Services\Ai\Channels\ProviderPolicy::class)->assertEnabled('gpt');
        } catch (\App\Services\Ai\Channels\ProviderUnavailable $e) {
            throw new GptFailure($e->getMessage());
        }
        // Ключ передаємо лише на фіксований домен OpenAI, без переходів за редиректами.
        return Http::baseUrl('https://api.openai.com/v1')->withToken($key)->acceptJson()
            ->connectTimeout(10)->timeout($timeout)->withOptions(['allow_redirects' => false]);
    }

    public function models(#[\SensitiveParameter] string $key): array
    {
        try {
            $response = $this->request($key, 15)->get('/models');
        } catch (ConnectionException) {
            throw new GptFailure('Не вдалося з’єднатися з OpenAI. Перевірте з’єднання та повторіть перевірку.');
        }
        $this->ensureSuccessful($response);

        return collect($response->json('data', []))->pluck('id')
            ->filter(fn ($id) => is_string($id) && str_starts_with($id, 'gpt-')
                && ! preg_match('/audio|realtime|transcri|tts|image|codex|search/i', $id))
            ->unique()->sort()->values()->all();
    }

    public function respond(#[\SensitiveParameter] string $key, string $model, array $input, string $instructions, array $tools, bool $allowTools, int $timeout, int $maxTokens = 4096): array
    {
        try {
            $response = $this->request($key, $timeout)->post('/responses', [
                'model' => $model,
                'store' => false,
                'include' => ['reasoning.encrypted_content'],
                'max_output_tokens' => $maxTokens,
                'instructions' => $instructions,
                'input' => $input,
                'tools' => $tools,
                'tool_choice' => $allowTools ? 'auto' : 'none',
                'parallel_tool_calls' => false,
            ]);
        } catch (ConnectionException) {
            throw new GptFailure('OpenAI не відповів вчасно. Повідомлення та фото збережені — можна повторити відповідь.');
        }
        $this->ensureSuccessful($response);

        if ($response->json('status') !== 'completed') {
            throw new GptFailure('OpenAI не завершив відповідь. Спробуйте коротший запит або іншу модель та повторіть.');
        }

        // Зберігаємо також службові елементи відповіді для коректного продовження діалогу.
        $output = $response->json('output', []);
        if (! is_array($output)) {
            throw new GptFailure('OpenAI повернув некоректну відповідь. Повторіть запит.');
        }
        $calls = collect($output)->where('type', 'function_call')->values()->all();
        if (count($calls) > 4) {
            throw new GptFailure('Модель запитала забагато дій одночасно. Уточніть запит.');
        }
        foreach ($calls as $call) {
            if (! is_string($call['call_id'] ?? null) || ! is_string($call['name'] ?? null) || ! is_string($call['arguments'] ?? null)) {
                throw new GptFailure('OpenAI повернув некоректний виклик інструмента. Повторіть запит.');
            }
        }
        $text = collect($output)->where('type', 'message')->where('role', 'assistant')
            ->flatMap(fn ($item) => $item['content'] ?? [])
            ->map(fn ($part) => match ($part['type'] ?? '') {
                'output_text' => $part['text'] ?? '',
                'refusal' => $part['refusal'] ?? '',
                default => '',
            })->filter()->implode("\n");

        return ['text' => trim($text), 'output' => $output, 'calls' => $calls, 'tokens_in' => (int) $response->json('usage.input_tokens', 0), 'tokens_out' => (int) $response->json('usage.output_tokens', 0)];
    }

    private function ensureSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        // Не повертаємо сире тіло помилки: воно може містити ключ або текст запиту.
        throw new GptFailure(match ($response->status()) {
            401 => 'OpenAI відхилив API-ключ. Перевірте його в налаштуваннях.',
            403 => 'Ключ OpenAI не має доступу до цієї дії або моделі.',
            404 => 'Вибрана модель недоступна. Перевірте її назву та доступ для вашого ключа.',
            429 => 'Досягнуто ліміту OpenAI або вичерпано баланс API. Перевірте квоту та повторіть пізніше.',
            400, 422 => 'OpenAI не прийняв запит. Перевірте підтримку тексту й зображень вибраною моделлю.',
            default => 'OpenAI тимчасово недоступний (HTTP '.$response->status().'). Повторіть спробу.',
        });
    }
}
