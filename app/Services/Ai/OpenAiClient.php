<?php

namespace App\Services\Ai;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

/** Адаптер Responses API до спільного формату історії, інструментів і статистики агента. */
class OpenAiClient
{
    public function messages(string $apiKey, array $payload, int $timeout = 40): Response
    {
        $request = [
            'model' => $payload['model'],
            'instructions' => is_array($payload['system'] ?? null)
                ? implode("\n\n", array_column($payload['system'], 'text'))
                : ($payload['system'] ?? ''),
            'input' => $this->input($payload['messages'] ?? []),
            // Контекст зберігає CRM; reasoning повертаємо лише в межах поточного ходу.
            'store' => false,
            'include' => ['reasoning.encrypted_content'],
            'max_output_tokens' => max(4096, (int) ($payload['max_tokens'] ?? 700)),
        ];

        if (preg_match('/^gpt-6(?:\.1)?-(?:astra|sol|luna)(?:-|$)/', $payload['model'])) {
            $request['reasoning'] = ['effort' => 'low'];
        }

        if (! empty($payload['tools'])) {
            $request['tools'] = array_map(fn ($tool) => [
                'type' => 'function',
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => $this->strictSchema($tool['input_schema']),
                'strict' => true,
            ], $payload['tools']);
            // Дії з побічними ефектами виконуємо послідовно, як у наявному агенті.
            $request['parallel_tool_calls'] = false;
            if (($payload['tool_choice']['type'] ?? '') === 'none') {
                $request['tool_choice'] = 'none';
            }
        }

        $r = Http::timeout($timeout)->withToken($apiKey)->post('https://api.openai.com/v1/responses', $request);
        if (! $r->successful()) {
            return $r;
        }

        $usage = [
            // input_tokens OpenAI вже містить кешовані токени — вдруге їх не додаємо.
            'input_tokens' => (int) $r->json('usage.input_tokens', 0),
            'output_tokens' => (int) $r->json('usage.output_tokens', 0),
        ];
        if ($r->json('status') !== 'completed') {
            return $this->response([
                'error' => ['message' => $r->json('error.message') ?: ('Незавершена відповідь OpenAI: '.($r->json('incomplete_details.reason') ?: $r->json('status', 'невідомо')))],
                'usage' => $usage,
            ], 502);
        }

        $content = [];
        foreach ($r->json('output', []) as $item) {
            if (($item['type'] ?? '') === 'message') {
                foreach ($item['content'] ?? [] as $block) {
                    if (($block['type'] ?? '') === 'output_text') {
                        $content[] = ['type' => 'text', 'text' => $block['text']];
                    } elseif (($block['type'] ?? '') === 'refusal') {
                        return $this->response(['error' => ['message' => 'OpenAI відмовився формувати відповідь.'], 'usage' => $usage], 422);
                    }
                }
            } elseif (($item['type'] ?? '') === 'function_call') {
                try {
                    $arguments = json_decode($item['arguments'], false, 512, JSON_THROW_ON_ERROR);
                    if (! is_object($arguments) || empty($item['call_id']) || empty($item['name'])) {
                        throw new JsonException('Некоректний виклик інструмента');
                    }
                    $content[] = ['type' => 'tool_use', 'id' => $item['call_id'], 'name' => $item['name'], 'input' => json_decode($item['arguments'], true, 512, JSON_THROW_ON_ERROR)];
                } catch (JsonException $e) {
                    return $this->response(['error' => ['message' => 'OpenAI повернув некоректні аргументи інструмента.'], 'usage' => $usage], 502);
                }
            }
        }

        return $this->response([
            'content' => $content,
            'stop_reason' => collect($content)->contains('type', 'tool_use') ? 'tool_use' : 'end_turn',
            'usage' => $usage,
            // Потрібні call_id та encrypted reasoning для наступного кроку Responses API.
            'provider_output' => $r->json('output', []),
        ]);
    }

    private function response(array $body, int $status = 200): Response
    {
        return new Response(new PsrResponse($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)));
    }

    private function input(array $messages): array
    {
        $input = [];
        foreach ($messages as $message) {
            if (! empty($message['provider_output'])) {
                array_push($input, ...$message['provider_output']);

                continue;
            }
            $role = $message['role'];
            $blocks = is_array($message['content']) ? $message['content'] : [['type' => 'text', 'text' => $message['content']]];
            $pending = [];
            $flush = function () use (&$pending, &$input, $role): void {
                if ($pending !== []) {
                    $input[] = ['role' => $role, 'content' => $role === 'assistant' ? implode("\n", array_column($pending, 'text')) : $pending];
                    $pending = [];
                }
            };
            foreach ($blocks as $block) {
                switch ($block['type']) {
                    case 'text':
                        $pending[] = ['type' => $role === 'assistant' ? 'output_text' : 'input_text', 'text' => $block['text']];
                        break;
                    case 'image':
                        $source = $block['source'];
                        $pending[] = ['type' => 'input_image', 'image_url' => 'data:'.$source['media_type'].';base64,'.$source['data']];
                        break;
                    case 'tool_use':
                        $flush();
                        $input[] = ['type' => 'function_call', 'call_id' => $block['id'], 'name' => $block['name'], 'arguments' => json_encode((object) $block['input'], JSON_THROW_ON_ERROR)];
                        break;
                    case 'tool_result':
                        $flush();
                        $input[] = ['type' => 'function_call_output', 'call_id' => $block['tool_use_id'], 'output' => $block['content']];
                        break;
                }
            }
            $flush();
        }

        return $input;
    }

    /** Strict mode вимагає всі ключі; необов'язкові поля дозволяють null. */
    private function strictSchema(array $schema): array
    {
        if (($schema['type'] ?? '') === 'object') {
            $properties = (array) ($schema['properties'] ?? []);
            $required = $schema['required'] ?? [];
            foreach ($properties as $name => $property) {
                $property = $this->strictSchema($property);
                if (! in_array($name, $required, true)) {
                    $property['type'] = array_values(array_unique(array_merge((array) $property['type'], ['null'])));
                }
                $properties[$name] = $property;
            }
            $schema['properties'] = (object) $properties;
            $schema['required'] = array_keys($properties);
            $schema['additionalProperties'] = false;
        } elseif (($schema['type'] ?? '') === 'array' && isset($schema['items'])) {
            $schema['items'] = $this->strictSchema($schema['items']);
        }

        return $schema;
    }
}
