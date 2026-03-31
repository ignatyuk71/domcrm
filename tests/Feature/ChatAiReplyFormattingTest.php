<?php

namespace Tests\Feature;

use App\Services\ChatAiKnowledgeService;
use App\Services\ChatAiOrchestratorService;
use App\Services\ChatAiSettingsService;
use App\Services\ChatService;
use App\Services\MetaService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
        $knowledgeService->method('productCatalogContext')
            ->willReturn([]);
        $knowledgeService->method('productMapContext')
            ->willReturn([]);
        $knowledgeService->method('resolveMappedProduct')
            ->willReturn(null);

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

    public function test_build_model_messages_includes_current_inbound_image_as_image_url(): void
    {
        $service = $this->makeService();
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('buildModelMessages');
        $method->setAccessible(true);

        $promptVersion = new \App\Models\ChatAiPromptVersion();
        $promptVersion->system_prompt = 'Тестовий prompt.';
        $promptVersion->policy_json = [];

        $state = new \App\Models\ChatAiConversationState();
        $state->stage = 'interest';
        $state->intent_purchase = false;
        $state->slots_json = [];
        $state->missing_slots_json = [];

        $conversation = new \App\Models\ChatConversation();
        $conversation->id = 10;

        $oldInbound = new \App\Models\ChatMessage([
            'id' => 100,
            'direction' => 'inbound',
            'text' => '',
            'message_type' => 'image',
        ]);
        $oldInbound->id = 100;
        $oldInbound->setRelation('attachments', new EloquentCollection([
            new \App\Models\ChatMessageAttachment([
                'attachment_type' => 'image',
                'public_url' => 'https://example.com/old-image.jpg',
            ]),
        ]));

        $currentInbound = new \App\Models\ChatMessage([
            'id' => 101,
            'direction' => 'inbound',
            'text' => 'Є така модель?',
            'message_type' => 'image',
        ]);
        $currentInbound->id = 101;
        $currentInbound->setRelation('attachments', new EloquentCollection([
            new \App\Models\ChatMessageAttachment([
                'attachment_type' => 'image',
                'public_url' => 'https://example.com/current-image.jpg',
            ]),
        ]));

        $messages = $method->invoke(
            $service,
            collect([$oldInbound, $currentInbound]),
            $promptVersion,
            $state,
            $conversation,
            'instagram',
            'Є така модель?',
            101
        );

        $this->assertCount(4, $messages);
        $this->assertSame('[надіслано зображення]', $messages[2]['content']);
        $this->assertIsArray($messages[3]['content']);
        $this->assertSame('text', $messages[3]['content'][0]['type']);
        $this->assertSame('Є така модель?', $messages[3]['content'][0]['text']);
        $this->assertSame('image_url', $messages[3]['content'][1]['type']);
        $this->assertSame(
            'https://example.com/current-image.jpg',
            $messages[3]['content'][1]['image_url']['url']
        );
    }
}
