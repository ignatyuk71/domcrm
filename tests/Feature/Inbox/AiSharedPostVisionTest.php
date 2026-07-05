<?php

namespace Tests\Feature\Inbox;

use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
use App\Services\Ai\AiAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Сторож кейсів 05.07: пересланий пост (ig_post) має потрапляти в «зір»
 * (раніше відсіювався → «фото не долучилося»), а рілс/відео — давати чесну
 * системну позначку «відео переглянути не можу» замість тієї ж відмовки.
 */
class AiSharedPostVisionTest extends TestCase
{
    use RefreshDatabase;

    private function makeConversation(): InboxConversation
    {
        $conn = MetaConnection::create(['page_id' => 'P_SP', 'page_name' => 'Shop', 'page_access_token' => 'tok', 'status' => 'active']);
        \App\Models\AiSetting::global()->update(['api_key' => 'sk-ant-test', 'model' => 'claude-sonnet-5']);
        \App\Models\AiSetting::forConnection($conn->id)->update(['enabled' => true, 'system_prompt' => 'Ти продавець.']);
        $contact = InboxContact::create(['meta_connection_id' => $conn->id, 'channel' => 'instagram', 'external_id' => 'U_SP']);

        return InboxConversation::create(['meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'instagram']);
    }

    /** Мінімальний валідний JPEG для vision-гілки. */
    private function jpeg(): string
    {
        return base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==');
    }

    private function fakeApis(): void
    {
        Http::fake([
            'cdn.meta/*' => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Це наші вуличні капці — 530 грн 🙂']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 300, 'output_tokens' => 20],
            ], 200),
            'graph.facebook.com/*' => Http::response(['message_id' => 'm_sp_out'], 200),
        ]);
    }

    private function anthropicBody(): string
    {
        $req = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->first(fn ($r) => str_contains($r->url(), 'api.anthropic.com'));
        $this->assertNotNull($req, 'запит до Anthropic не знайдено');

        return json_encode($req->data(), JSON_UNESCAPED_UNICODE);
    }

    public function test_shared_ig_post_image_reaches_vision(): void
    {
        $conv = $this->makeConversation();
        // Пересланий пост ОКРЕМИМ повідомленням + питання наступним (кейс Оксани К.)
        InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_sp1', 'attachments' => [['type' => 'ig_post', 'url' => 'https://cdn.meta/post-image.jpg']],
            'sent_at' => now(),
        ]);
        $q = InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_sp2', 'text' => 'Яка ціна даної моделі?', 'sent_at' => now(),
        ]);

        $this->fakeApis();
        (new \App\Jobs\AiRespondToMessage($conv->id, $q->id))->handle(app(AiAgentService::class));

        $body = $this->anthropicBody();
        $this->assertStringContainsString('"type":"image"', $body, 'картинка пересланого поста мусить піти в зір');
        $this->assertStringContainsString('base64', $body);
    }

    public function test_reel_gets_honest_video_note_instead_of_no_photo(): void
    {
        $conv = $this->makeConversation();
        // Рілс (відео) + питання (кейс соні)
        InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_sp3', 'attachments' => [['type' => 'ig_reel', 'url' => 'https://www.instagram.com/reel/XXX/']],
            'sent_at' => now(),
        ]);
        $q = InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_sp4', 'text' => 'есть такие в наличии и какая цена', 'sent_at' => now(),
        ]);

        $this->fakeApis();
        (new \App\Jobs\AiRespondToMessage($conv->id, $q->id))->handle(app(AiAgentService::class));

        $body = $this->anthropicBody();
        $this->assertStringContainsString('клієнт надіслав ВІДЕО або РІЛС', $body);
        $this->assertStringContainsString('НЕ кажи «фото не долучилося»', $body);
        $this->assertStringNotContainsString('"type":"image"', $body, 'рілс не мусить давати картинку');
    }
}