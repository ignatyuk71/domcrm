<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OpenAiResponsesService
{
    public function isConfigured(): bool
    {
        return trim((string) config('services.openai.api_key')) !== '';
    }

    /**
     * Виконує structured output запит до Responses API та повертає масив.
     *
     * @param  array<int|string, mixed>|string  $input
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function createStructuredResponse(
        string $instructions,
        array|string $input,
        array $schema,
        string $schemaName = 'chat_triage',
        ?string $model = null
    ): array {
        if (!$this->isConfigured()) {
            throw new RuntimeException('OPENAI_API_KEY не налаштований.');
        }

        $response = Http::baseUrl(rtrim((string) config('services.openai.base_url'), '/'))
            ->timeout((int) config('services.openai.timeout', 30))
            ->retry(2, 400)
            ->withToken((string) config('services.openai.api_key'))
            ->acceptJson()
            ->post('responses', [
                'model' => $model ?: (string) config('services.openai.model', 'gpt-4.1-mini'),
                'instructions' => $instructions,
                'input' => $input,
                'store' => (bool) config('services.openai.store', false),
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => $schemaName,
                        'strict' => true,
                        'schema' => $schema,
                    ],
                ],
            ]);

        if ($response->failed()) {
            Log::warning('OpenAI Responses API error', [
                'status' => $response->status(),
                'body' => $response->json() ?: $response->body(),
            ]);

            throw new RuntimeException('Не вдалося отримати відповідь від OpenAI.');
        }

        $payload = $response->json();
        $rawText = $this->extractOutputText($payload);

        if ($rawText === null || trim($rawText) === '') {
            throw new RuntimeException('OpenAI повернув порожню structured відповідь.');
        }

        try {
            $decoded = json_decode($rawText, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            Log::warning('OpenAI structured output parse failed', [
                'raw_text' => $rawText,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Не вдалося розібрати structured відповідь OpenAI.');
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('OpenAI повернув неочікуваний формат structured відповіді.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractOutputText(array $payload): ?string
    {
        $outputText = data_get($payload, 'output_text');
        if (is_string($outputText) && trim($outputText) !== '') {
            return $outputText;
        }

        foreach ((array) data_get($payload, 'output', []) as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue;
            }

            foreach ((array) ($item['content'] ?? []) as $content) {
                if (in_array($content['type'] ?? null, ['output_text', 'text'], true)) {
                    $text = $content['text'] ?? null;
                    if (is_string($text) && trim($text) !== '') {
                        return $text;
                    }
                }
            }
        }

        return null;
    }
}
