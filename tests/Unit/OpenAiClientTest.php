<?php

namespace Tests\Unit;

use App\Services\Ai\OpenAiClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiClientTest extends TestCase
{
    private function payload(array $extra = []): array
    {
        return $extra + ['model' => 'gpt-6.1-sol', 'system' => [['type' => 'text', 'text' => 'Правила', 'cache_control' => ['type' => 'ephemeral']]], 'messages' => [['role' => 'user', 'content' => 'Ціна?']], 'max_tokens' => 700];
    }

    private function completed(array $output): array
    {
        return ['status' => 'completed', 'output' => $output, 'usage' => ['input_tokens' => 100, 'input_tokens_details' => ['cached_tokens' => 80], 'output_tokens' => 20]];
    }

    public function test_maps_images_instructions_and_usage_without_anthropic_parameters(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->completed([
            ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '530 грн']]],
        ]))]);

        $response = app(OpenAiClient::class)->messages('sk-openai-test', $this->payload(['messages' => [
            ['role' => 'assistant', 'content' => 'Вітаю!'],
            ['role' => 'user', 'content' => [['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'YWJj']], ['type' => 'text', 'text' => 'Яка ціна?']]],
        ], 'thinking' => ['type' => 'disabled']]));

        $this->assertSame('530 грн', $response->json('content.0.text'));
        $this->assertSame(100, $response->json('usage.input_tokens'));
        $this->assertSame('end_turn', $response->json('stop_reason'));
        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->hasHeader('Authorization', 'Bearer sk-openai-test')
                && $body['store'] === false && $body['instructions'] === 'Правила'
                && $body['input'][0]['content'] === 'Вітаю!'
                && $body['input'][1]['content'][0]['image_url'] === 'data:image/png;base64,YWJj'
                && $body['input'][1]['content'][1]['type'] === 'input_text'
                && $body['reasoning']['effort'] === 'low'
                && $body['max_output_tokens'] >= 4096
                && ! isset($body['thinking'], $body['temperature'], $body['system']);
        });
    }

    public function test_replays_reasoning_and_tool_calls_with_their_original_call_ids(): void
    {
        $output = [
            ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'encrypted'],
            ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_product', 'name' => 'get_product', 'arguments' => '{"product_id":42}'],
        ];
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push($this->completed($output))
            ->push($this->completed([['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Є в наявності']]]]))]);

        $client = app(OpenAiClient::class);
        $first = $client->messages('sk-test', $this->payload());
        $this->assertSame('tool_use', $first->json('stop_reason'));
        $this->assertSame('call_product', $first->json('content.0.id'));
        $client->messages('sk-test', $this->payload(['messages' => [
            ['role' => 'user', 'content' => 'Ціна?'],
            ['role' => 'assistant', 'content' => $first->json('content'), 'provider_output' => $first->json('provider_output')],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'call_product', 'content' => '{"ціна":"530 грн"}'], ['type' => 'text', 'text' => 'Відповідай стисло']]],
        ]]));

        $body = Http::recorded()[1][0]->data();
        $this->assertSame($output[0], $body['input'][1]);
        $this->assertSame($output[1], $body['input'][2]);
        $this->assertSame('call_product', $body['input'][3]['call_id']);
        $this->assertSame('function_call_output', $body['input'][3]['type']);
        $this->assertSame('Відповідай стисло', $body['input'][4]['content'][0]['text']);
    }

    public function test_strict_schemas_keep_optional_nested_fields_nullable_and_empty_properties_objects(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->completed([]))]);
        app(OpenAiClient::class)->messages('sk-test', $this->payload(['model' => 'gpt-4.1', 'tool_choice' => ['type' => 'none'], 'tools' => [
            ['name' => 'complete_order', 'description' => 'Замовлення', 'input_schema' => ['type' => 'object', 'properties' => ['items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'price' => ['type' => 'number']], 'required' => ['title']]]], 'required' => ['items']]],
            ['name' => 'ask_delivery_details', 'description' => 'Доставка', 'input_schema' => ['type' => 'object', 'properties' => (object) []]],
        ]]));
        $body = json_decode(Http::recorded()[0][0]->body(), true);
        $schema = $body['tools'][0]['parameters'];
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['title', 'price'], $schema['properties']['items']['items']['required']);
        $this->assertSame(['number', 'null'], $schema['properties']['items']['items']['properties']['price']['type']);
        $this->assertFalse($body['parallel_tool_calls']);
        $this->assertSame('none', $body['tool_choice']);
        $this->assertArrayNotHasKey('reasoning', $body);
        $this->assertStringContainsString('"properties":{}', Http::recorded()[0][0]->body());
    }

    public function test_incomplete_response_never_exposes_partial_tool_calls(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'], 'output' => [['type' => 'function_call', 'name' => 'complete_order']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 4096]])]);
        $response = app(OpenAiClient::class)->messages('sk-test', $this->payload());
        $this->assertSame(502, $response->status());
        $this->assertNull($response->json('content'));
        $this->assertStringContainsString('max_output_tokens', $response->json('error.message'));
        $this->assertSame(4096, $response->json('usage.output_tokens'));
    }

    public function test_malformed_tool_arguments_are_errors(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->completed([['type' => 'function_call', 'call_id' => 'call_bad', 'name' => 'complete_order', 'arguments' => '[]']]))]);
        $response = app(OpenAiClient::class)->messages('sk-test', $this->payload());
        $this->assertSame(502, $response->status());
        $this->assertNull($response->json('content'));
    }

    public function test_api_errors_and_refusals_are_not_customer_messages(): void
    {
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'Rate limit']], 429)
            ->push($this->completed([['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'Відмова']]]]))]);
        $client = app(OpenAiClient::class);
        $this->assertSame(429, $client->messages('sk-test', $this->payload())->status());
        $this->assertSame(422, $client->messages('sk-test', $this->payload())->status());
    }
}
