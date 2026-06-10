<?php

namespace Tests\Feature\Inbox;

use App\Jobs\AiRespondToMessage;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
use App\Services\Ai\AiAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiAgentTest extends TestCase
{
    use RefreshDatabase;

    private MetaConnection $conn;
    private InboxConversation $conv;
    private InboxMessage $incoming;

    private function setUpConversation(bool $withKey = true, bool $storeEnabled = true): void
    {
        $this->conn = MetaConnection::create([
            'page_id' => 'PAGE1', 'page_name' => 'Test Shop', 'page_access_token' => 'tok', 'status' => 'active',
        ]);

        if ($withKey) {
            AiSetting::global()->update(['api_key' => 'sk-ant-test', 'model' => 'claude-sonnet-4-6']);
        }
        AiSetting::forConnection($this->conn->id)->update([
            'enabled' => $storeEnabled,
            'system_prompt' => 'Ти продавець Test Shop.',
        ]);

        $contact = InboxContact::create([
            'meta_connection_id' => $this->conn->id, 'channel' => 'facebook', 'external_id' => 'USER1', 'name' => 'Іван',
        ]);
        $this->conv = InboxConversation::create([
            'meta_connection_id' => $this->conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook',
        ]);
        $this->incoming = InboxMessage::create([
            'inbox_conversation_id' => $this->conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_in_1', 'text' => 'Скільки коштує доставка?', 'sent_at' => now(),
        ]);
    }

    private function runJob(): void
    {
        (new AiRespondToMessage($this->conv->id, $this->incoming->id))->handle(app(AiAgentService::class));
    }

    public function test_ai_replies_and_stores_message(): void
    {
        $this->setUpConversation();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Вітаю! Доставка Новою поштою.']],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
            ], 200),
            'graph.facebook.com/*' => Http::response(['message_id' => 'm_ai_1'], 200),
        ]);

        $this->runJob();

        $this->assertDatabaseHas('inbox_messages', [
            'inbox_conversation_id' => $this->conv->id,
            'direction' => 'out',
            'sender' => 'ai',
            'text' => 'Вітаю! Доставка Новою поштою.',
            'external_message_id' => 'm_ai_1',
        ]);
        $this->assertDatabaseHas('ai_runs', [
            'inbox_conversation_id' => $this->conv->id,
            'status' => 'replied',
            'tokens_in' => 100,
            'tokens_out' => 20,
        ]);
        $this->assertSame('out', $this->conv->fresh()->last_message_direction);

        // Перевіряємо, що в Claude пішла історія і system prompt (json екранує юнікод)
        Http::assertSent(function ($request) {
            $prompt = trim((string) json_encode('Ти продавець Test Shop.'), '"');
            $question = trim((string) json_encode('Скільки коштує доставка?'), '"');

            return str_contains($request->url(), 'api.anthropic.com')
                && str_contains($request->body(), $prompt)
                && str_contains($request->body(), $question);
        });
    }

    public function test_skips_when_conversation_ai_off(): void
    {
        $this->setUpConversation();
        $this->conv->update(['ai_enabled' => false]);
        Http::fake();

        $this->runJob();

        $this->assertDatabaseHas('ai_runs', ['status' => 'skipped_conversation_off']);
        $this->assertDatabaseMissing('inbox_messages', ['sender' => 'ai']);
        Http::assertNothingSent();
    }

    public function test_skips_without_api_key(): void
    {
        $this->setUpConversation(withKey: false);
        Http::fake();

        $this->runJob();

        $this->assertDatabaseHas('ai_runs', ['status' => 'skipped_no_key']);
        Http::assertNothingSent();
    }

    public function test_skips_when_store_disabled(): void
    {
        $this->setUpConversation(storeEnabled: false);
        Http::fake();

        $this->runJob();

        $this->assertDatabaseHas('ai_runs', ['status' => 'skipped_store_off']);
        Http::assertNothingSent();
    }

    public function test_skips_stale_when_newer_message_arrived(): void
    {
        $this->setUpConversation();
        // Клієнт встиг написати ще одне — стара джоба має змовчати
        InboxMessage::create([
            'inbox_conversation_id' => $this->conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_in_2', 'text' => 'І ще питання', 'sent_at' => now(),
        ]);
        Http::fake();

        $this->runJob();

        $this->assertDatabaseHas('ai_runs', ['status' => 'skipped_stale']);
        Http::assertNothingSent();
    }

    public function test_claude_error_is_logged_not_sent(): void
    {
        $this->setUpConversation();
        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => ['message' => 'overloaded']], 529),
        ]);

        $this->runJob();

        $run = AiRun::where('status', 'error')->first();
        $this->assertNotNull($run);
        $this->assertStringContainsString('overloaded', (string) $run->error);
        $this->assertDatabaseMissing('inbox_messages', ['sender' => 'ai']);
    }
}
