<?php

namespace Tests\Feature\Inbox;

use App\Models\AiChannelSetting;
use App\Models\AiSetting;
use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
use App\Services\Ai\Channels\Dispatcher;
use App\Services\Ai\Gpt\MessageBuffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GptBufferIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 12:00:00.250000');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_provider_switch_during_gpt_pause_does_not_call_either_api(): void
    {
        $conversation = $this->conversation();
        $message = $this->incoming($conversation, 'Яка доставка?');
        $buffer = $this->buffer();
        $buffer->received($message);
        $buffer->shouldReceive('pause')->once()->andReturnUsing(function (float $seconds): void {
            Carbon::setTestNow(now()->addMicroseconds((int) ceil($seconds * 1_000_000)));
            // Обидва API доступні: саме зміна вибору має зупинити стару задачу GPT.
            AiChannelSetting::current()->update(['default_provider' => 'claude', 'claude_enabled' => true]);
        });
        Http::fake();

        $run = app(Dispatcher::class)->respond($conversation, $message->id);

        $this->assertSame('skipped_stale', $run->status);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('inbox_messages', ['inbox_conversation_id' => $conversation->id, 'sender' => 'ai']);
    }

    public function test_human_request_in_a_batch_can_handoff_after_a_size_question(): void
    {
        $conversation = $this->conversation();
        $first = $this->incoming($conversation, 'Покличте менеджера, будь ласка.');
        $last = $this->incoming($conversation, 'Який розмір взяти?');
        $buffer = $this->buffer();
        $buffer->received($first);
        $buffer->received($last);
        $buffer->shouldReceive('pause')->once()->andReturnUsing(function (float $seconds): void {
            Carbon::setTestNow(now()->addMicroseconds((int) ceil($seconds * 1_000_000)));
        });
        $calls = $sent = 0;
        Http::fake(function ($request) use (&$calls, &$sent) {
            if (str_contains($request->url(), 'api.openai.com')) {
                $this->assertTrue(collect($request['tools'])->contains('name', 'escalate_to_manager'));
                if (++$calls === 1) {
                    $input = json_encode($request['input'], JSON_UNESCAPED_UNICODE);
                    $this->assertStringContainsString('Покличте менеджера', $input);
                    $this->assertStringContainsString('Який розмір взяти?', $input);

                    return Http::response(['status' => 'completed', 'output' => [
                        ['type' => 'function_call', 'name' => 'escalate_to_manager', 'call_id' => 'batch-handoff', 'arguments' => '{}'],
                    ]]);
                }

                return Http::response(['status' => 'completed', 'output' => [
                    ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Передаю менеджеру.']]],
                ]]);
            }
            $this->assertStringContainsString('graph.facebook.com', $request->url());

            return Http::response(['message_id' => 'batch-handoff-'.(++$sent)]);
        });

        $run = app(Dispatcher::class)->respond($conversation, $first->id);
        $conversation->refresh();

        $this->assertSame('replied', $run->status);
        $this->assertSame($last->id, $run->inbox_message_id);
        $this->assertSame('needs_human', $conversation->ai_checkout_data['status'] ?? null);
        $this->assertFalse($conversation->ai_enabled);
        $this->assertSame('needs_human', $conversation->chatStatus->code);
        $this->assertSame(2, $calls);
        $this->assertSame(1, $sent);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'anthropic'));
    }

    private function buffer(): MessageBuffer
    {
        $buffer = \Mockery::mock(MessageBuffer::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $this->app->instance(MessageBuffer::class, $buffer);

        return $buffer;
    }

    private function conversation(): InboxConversation
    {
        $connection = MetaConnection::create(['page_id' => 'ISOLATION_PAGE', 'page_name' => 'Магазин', 'page_access_token' => 'test-token', 'status' => 'active']);
        AiSetting::global()->update(['api_key' => 'sk-ant-test', 'model' => 'claude-sonnet-4-6']);
        AiSetting::forConnection($connection->id)->update(['enabled' => true, 'system_prompt' => 'Короткі відповіді.']);
        AiChannelSetting::current()->update(['gpt_enabled' => true, 'claude_enabled' => false,
            'default_provider' => 'gpt', 'gpt_api_key' => 'sk-test-key', 'gpt_model' => 'gpt-test']);
        $contact = InboxContact::create(['meta_connection_id' => $connection->id, 'channel' => 'facebook', 'external_id' => 'ISOLATION_USER']);

        return InboxConversation::create(['meta_connection_id' => $connection->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook', 'ai_enabled' => true]);
    }

    private function incoming(InboxConversation $conversation, string $text): InboxMessage
    {
        return $conversation->messages()->create(['direction' => 'in', 'sender' => 'contact', 'text' => $text, 'sent_at' => now()]);
    }
}
