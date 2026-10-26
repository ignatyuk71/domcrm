<?php

namespace Tests\Feature\Inbox;

use App\Jobs\AiReplyToComment;
use App\Models\AiPhoto;
use App\Models\AiPhotoGroup;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\InboxComment;
use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
use App\Models\Product;
use App\Services\Ai\AiAgentService;
use App\Services\Meta\MetaSendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiProvidersTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(string $suffix = '1', ?string $provider = null): array
    {
        $connection = MetaConnection::create(['page_id' => 'P'.$suffix, 'page_name' => 'Магазин', 'page_access_token' => 'meta-test', 'status' => 'active']);
        AiSetting::forConnection($connection->id)->update(['enabled' => true, 'provider' => $provider]);
        $contact = InboxContact::create(['meta_connection_id' => $connection->id, 'external_id' => 'U'.$suffix, 'channel' => 'facebook']);
        $conversation = InboxConversation::create(['meta_connection_id' => $connection->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook', 'ai_enabled' => true]);
        $message = InboxMessage::create(['inbox_conversation_id' => $conversation->id, 'sender' => 'contact', 'direction' => 'in', 'text' => 'Ціна?', 'sent_at' => now()]);

        return [$conversation, $message];
    }

    private function openaiReply(array $output = []): array
    {
        return ['status' => 'completed', 'output' => $output ?: [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '530 грн 🙂']]]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 20]];
    }

    private function fakeProviders(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '530 грн 🙂']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 30, 'output_tokens' => 10]]),
            'api.openai.com/*' => Http::response($this->openaiReply()),
            'graph.facebook.com/*' => fn () => Http::response(['message_id' => uniqid('meta_')]),
        ]);
    }

    public function test_saved_openai_credentials_do_not_switch_claude_by_default(): void
    {
        AiSetting::global()->update(['api_key' => 'sk-ant-existing', 'openai_api_key' => 'sk-openai-new']);
        [$conversation, $message] = $this->conversation();
        $this->fakeProviders();
        $run = app(AiAgentService::class)->respond($conversation, $message->id);
        $this->assertSame('replied', $run->status);
        $this->assertSame('claude', $run->provider);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));
    }

    public function test_global_openai_works_without_an_anthropic_key(): void
    {
        AiSetting::global()->update(['provider' => 'openai', 'openai_api_key' => 'sk-openai-test']);
        [$conversation, $message] = $this->conversation();
        $this->fakeProviders();
        $run = app(AiAgentService::class)->respond($conversation, $message->id);
        $this->assertSame('replied', $run->status);
        $this->assertSame('openai', $run->provider);
        $this->assertSame(AiSetting::DEFAULT_OPENAI_MODEL, $run->model);
        $this->assertSame(100, $run->tokens_in);
        $this->assertDatabaseHas('inbox_messages', ['sender' => 'ai', 'text' => '530 грн 🙂']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com'));
    }

    public function test_global_switch_changes_all_stores_and_can_return_to_claude(): void
    {
        AiSetting::global()->update(['api_key' => 'sk-ant-test', 'openai_api_key' => 'sk-openai-test']);
        [$first, $firstMessage] = $this->conversation('first');
        [$second, $secondMessage] = $this->conversation('second');
        $this->fakeProviders();
        $agent = app(AiAgentService::class);
        $this->assertSame('claude', $agent->respond($first, $firstMessage->id)->provider);

        AiSetting::global()->update(['provider' => 'openai']);
        $next = InboxMessage::create(['inbox_conversation_id' => $first->id, 'direction' => 'in', 'sender' => 'contact', 'text' => 'А розміри?', 'sent_at' => now()]);
        $this->assertSame('openai', $agent->respond($first->fresh(), $next->id)->provider);
        $this->assertSame('openai', $agent->respond($second, $secondMessage->id)->provider);

        AiSetting::global()->update(['provider' => 'claude']);
        $last = InboxMessage::create(['inbox_conversation_id' => $second->id, 'direction' => 'in', 'sender' => 'contact', 'text' => 'А кольори?', 'sent_at' => now()]);
        $this->assertSame('claude', $agent->respond($second->fresh(), $last->id)->provider);
        $this->assertSame('sk-openai-test', AiSetting::global()->openai_api_key);
    }

    public function test_global_selection_cannot_be_overridden_by_store_rows(): void
    {
        AiSetting::global()->update(['provider' => 'openai', 'api_key' => 'sk-ant-test', 'openai_api_key' => 'sk-openai-test']);
        [$conversation, $message] = $this->conversation('store', 'claude');
        $this->fakeProviders();
        $this->assertSame('openai', app(AiAgentService::class)->respond($conversation, $message->id)->provider);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com'));
    }

    public function test_missing_openai_key_never_uses_the_claude_key_or_sends_messages(): void
    {
        AiSetting::global()->update(['provider' => 'openai', 'api_key' => 'sk-ant-test']);
        [$conversation, $message] = $this->conversation('openai', 'openai');
        Http::fake();
        $run = app(AiAgentService::class)->respond($conversation, $message->id);
        $this->assertSame('skipped_no_key', $run->status);
        $this->assertSame('openai', $run->provider);
        Http::assertNothingSent();
    }

    public function test_openai_executes_existing_product_tool_and_pins_provider_during_turn(): void
    {
        AiSetting::global()->update(['provider' => 'openai', 'api_key' => 'sk-ant-test', 'openai_api_key' => 'sk-openai-test']);
        [$conversation, $message] = $this->conversation();
        $product = Product::create(['title' => 'Домашні капці', 'sku' => 'AI-TEST', 'sale_price' => 530, 'is_active' => true, 'description' => 'Хутряний верх']);
        $calls = 0;
        Http::preventStrayRequests();
        Http::fake([
            'api.openai.com/*' => function () use (&$calls, $product) {
                if (++$calls === 1) {
                    AiSetting::global()->update(['provider' => 'claude']);

                    return Http::response($this->openaiReply([['type' => 'function_call', 'call_id' => 'call_product', 'name' => 'get_product', 'arguments' => json_encode(['product_id' => $product->id])]]));
                }

                return Http::response($this->openaiReply());
            },
            'graph.facebook.com/*' => Http::response(['message_id' => 'meta_product']),
        ]);
        $run = app(AiAgentService::class)->respond($conversation, $message->id);
        $this->assertSame('replied', $run->status);
        $this->assertSame('openai', $run->provider);
        $this->assertSame(2, $calls);
        $this->assertSame('get_product', $run->tools_called[0]['tool']);
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'api.openai.com')) {
                return false;
            }
            $result = collect($request['input'])->first(fn ($item) => ($item['type'] ?? '') === 'function_call_output');

            return $result && $result['call_id'] === 'call_product' && str_contains($result['output'], 'Хутряний верх');
        });
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com'));
    }

    public function test_openai_uses_existing_photo_sending_tool(): void
    {
        AiSetting::global()->update(['provider' => 'openai', 'openai_api_key' => 'sk-openai-test']);
        [$conversation, $message] = $this->conversation();
        $group = AiPhotoGroup::create(['name' => 'Домашні']);
        $photo = AiPhoto::create(['ai_photo_group_id' => $group->id, 'path' => 'uploads/ai/test.jpg']);
        Http::preventStrayRequests();
        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->push($this->openaiReply([['type' => 'function_call', 'call_id' => 'call_photo', 'name' => 'send_photos', 'arguments' => json_encode(['photo_ids' => [$photo->id]])]]))
                ->push($this->openaiReply()),
            'graph.facebook.com/*' => fn () => Http::response(['message_id' => uniqid('photo_')]),
        ]);
        $run = app(AiAgentService::class)->respond($conversation, $message->id);
        $this->assertSame('replied', $run->status);
        $this->assertSame('send_photos', $run->tools_called[0]['tool']);
        $this->assertSame(1, $conversation->messages()->where('sender', 'ai')->whereNotNull('attachments')->count());
        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com') && isset($request['message']['attachment']));
    }

    public function test_openai_summary_uses_global_provider(): void
    {
        AiSetting::global()->update(['provider' => 'openai', 'api_key' => 'sk-ant-test', 'openai_api_key' => 'sk-openai-test']);
        [$conversation] = $this->conversation('openai', 'openai');
        $this->fakeProviders();
        $summary = app(AiAgentService::class)->summarizeConversation($conversation);
        $this->assertSame('530 грн 🙂', $summary);
        $this->assertNotNull($conversation->fresh()->ai_summary_at);
        $this->assertSame(0, AiRun::count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com') || str_contains($request->url(), 'graph.facebook.com'));
    }

    public function test_openai_completes_the_existing_order_handover_flow(): void
    {
        AiSetting::global()->update(['provider' => 'openai', 'openai_api_key' => 'sk-openai-test']);
        [$conversation, $message] = $this->conversation();
        $order = ['items' => [['title' => 'Домашні', 'color' => 'чорні', 'size' => '38/39', 'qty' => 1, 'price' => 530]], 'customer_name' => 'Іван Петренко', 'phone' => '0961234567', 'address' => 'Київ, НП 5', 'payment' => 'при отриманні'];
        Http::preventStrayRequests();
        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->push($this->openaiReply([['type' => 'function_call', 'call_id' => 'call_order', 'name' => 'complete_order', 'arguments' => json_encode($order)]]))
                ->push(['status' => 'completed', 'output' => []]),
            'graph.facebook.com/*' => Http::response(['message_id' => 'meta_order']),
        ]);
        $run = app(AiAgentService::class)->respond($conversation, $message->id);
        $this->assertSame('replied', $run->status);
        $this->assertFalse($conversation->fresh()->ai_enabled);
        $this->assertSame('Іван Петренко', $conversation->fresh()->ai_order_customer_name);
        $this->assertSame('ai_order', $conversation->fresh()->chatStatus->code);
        $this->assertDatabaseHas('inbox_messages', ['sender' => 'ai', 'text' => AiAgentService::orderTexts()['final_message']]);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_comment_classification_uses_the_global_provider(): void
    {
        AiSetting::global()->update(['provider' => 'openai', 'api_key' => 'sk-ant-test', 'openai_api_key' => 'sk-openai-test', 'comment_settings' => ['enabled' => true, 'facebook' => true]]);
        [$conversation] = $this->conversation('comments', 'openai');
        $group = AiPhotoGroup::create(['name' => 'Домашні капці']);
        $comment = InboxComment::create(['meta_connection_id' => $conversation->meta_connection_id, 'channel' => 'facebook', 'post_id' => 'post_openai', 'post_excerpt' => 'Наші домашні капці', 'comment_id' => 'comment_openai', 'from_id' => 'user_comment', 'text' => 'Ціна?', 'commented_at' => now()]);
        Http::preventStrayRequests();
        Http::fake([
            'api.openai.com/*' => Http::response($this->openaiReply([['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $group->name]]]])),
            'graph.facebook.com/*' => Http::response(['message_id' => 'meta_comment']),
        ]);
        (new AiReplyToComment($comment->id))->handle(app(MetaSendService::class));
        $this->assertSame('dm_sent', $comment->fresh()->status);
        $this->assertSame($group->name, $comment->fresh()->matched_group_name);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com'));
    }
}
