<?php

namespace Tests\Feature;

use App\Services\ChatAiKnowledgeService;
use App\Services\ChatAiOrchestratorService;
use App\Services\ChatAiSettingsService;
use App\Services\ChatService;
use App\Services\MetaService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ChatAiReplyFormattingTest extends TestCase
{
    private function makeService(): ChatAiOrchestratorService
    {
        $chatService = $this->createMock(ChatService::class);
        $metaService = $this->createMock(MetaService::class);
        $settingsService = $this->createMock(ChatAiSettingsService::class);
        $knowledgeService = $this->createMock(ChatAiKnowledgeService::class);

        $knowledgeService->method('buildKnowledgePromptBlock')
            ->willReturn('');

        return new ChatAiOrchestratorService(
            $chatService,
            $metaService,
            $settingsService,
            $knowledgeService
        );
    }

    public function test_build_safe_reply_preserves_meaningful_line_breaks(): void
    {
        $service = $this->makeService();
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('buildSafeReply');
        $method->setAccessible(true);

        $reply = "Домашні пухнасті тапочки — 380 грн.\n\nДоступні розміри:\n36/37\n38/39\n\nПоказати фото?";

        $result = $method->invoke($service, $reply, 'selection', [
            'intent_purchase' => false,
        ]);

        $this->assertSame(
            "Домашні пухнасті тапочки — 380 грн.\n\nДоступні розміри:\n36/37\n38/39\n\nПоказати фото?",
            $result
        );
    }

    public function test_system_prompt_requires_block_formatting_for_reply(): void
    {
        $service = $this->makeService();
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('buildSystemPrompt');
        $method->setAccessible(true);

        $promptVersion = new \App\Models\ChatAiPromptVersion();
        $promptVersion->system_prompt = 'Тестовий prompt.';
        $promptVersion->policy_json = [];

        $prompt = $method->invoke($service, $promptVersion);

        $this->assertStringContainsString('Правила форматування reply', $prompt);
        $this->assertStringContainsString('Кожен смисловий блок пиши з нового рядка', $prompt);
        $this->assertStringContainsString('Між окремими блоками став один порожній рядок', $prompt);
    }

    public function test_structured_output_response_format_uses_strict_json_schema(): void
    {
        $service = $this->makeService();
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('structuredOutputResponseFormat');
        $method->setAccessible(true);

        $responseFormat = $method->invoke($service);

        $this->assertSame('json_schema', $responseFormat['type'] ?? null);
        $this->assertSame('chat_ai_reply', $responseFormat['json_schema']['name'] ?? null);
        $this->assertTrue((bool) ($responseFormat['json_schema']['strict'] ?? false));
        $this->assertContains('delivery_fields', $responseFormat['json_schema']['schema']['required'] ?? []);
        $this->assertContains('cart_items', $responseFormat['json_schema']['schema']['required'] ?? []);
        $this->assertFalse((bool) ($responseFormat['json_schema']['schema']['additionalProperties'] ?? true));
    }
}
