<?php

namespace Tests\Feature\Inbox;

use App\Jobs\{AiReplyToComment, AiRespondToMessage};
use App\Models\{AiChannelSetting, AiPhoto, AiPhotoGroup, AiSetting, GptInboxReply, InboxComment, InboxContact, InboxConversation, InboxMessage, MetaConnection, Product, User};
use App\Services\Ai\Gpt\CatalogReader;
use App\Services\Ai\{AiAgentService, ClaudeClient};
use App\Services\Ai\Channels\{DispatchContext, Dispatcher, ProviderUnavailable};
use App\Services\Meta\MetaSendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB, Http, Storage};
use Tests\TestCase;

class AiChannelsTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(): array
    {
        $connection = MetaConnection::create(['page_id' => 'PAGE', 'page_name' => 'Магазин', 'page_access_token' => 'test-token', 'status' => 'active']);
        AiSetting::global()->update(['api_key' => 'sk-ant-test', 'model' => 'claude-sonnet-4-6']);
        AiSetting::forConnection($connection->id)->update(['enabled' => true, 'system_prompt' => 'Короткі відповіді.']);
        $contact = InboxContact::create(['meta_connection_id' => $connection->id, 'channel' => 'facebook', 'external_id' => 'USER']);
        $conversation = InboxConversation::create(['meta_connection_id' => $connection->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook', 'ai_enabled' => true]);
        $message = $conversation->messages()->create(['direction' => 'in', 'sender' => 'contact', 'text' => 'Яка доставка?', 'sent_at' => now()]);
        return [$conversation, $message];
    }

    private function gpt(array $changes = []): void
    {
        AiChannelSetting::current()->update($changes + ['gpt_enabled' => true, 'claude_enabled' => false,
            'default_provider' => 'gpt', 'gpt_api_key' => 'sk-test-live-key', 'gpt_model' => 'gpt-test']);
    }

    private function answer(string $text = 'Доставка Новою поштою.'): array
    {
        return ['status' => 'completed', 'usage' => ['input_tokens' => 15, 'output_tokens' => 5],
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]]]];
    }

    public function test_disabled_claude_receives_no_chat_summary_comment_or_key_test_requests(): void
    {
        [$conversation, $message] = $this->conversation();
        AiChannelSetting::current()->update(['claude_enabled' => false]);
        Http::fake();
        (new AiRespondToMessage($conversation->id, $message->id))->handle(app(AiAgentService::class));
        $this->assertDatabaseHas('ai_runs', ['status' => 'skipped_api_off']);
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->postJson('/api/inbox/conversations/'.$conversation->id.'/ai-summary')->assertStatus(422);
        $this->postJson('/settings/ai/test', ['api_key' => 'sk-ant-test'])->assertStatus(422);
        $comment = InboxComment::create(['meta_connection_id' => $conversation->meta_connection_id, 'channel' => 'facebook', 'comment_id' => 'c1', 'post_id' => 'p1', 'from_id' => 'U', 'text' => 'Ціна?', 'status' => 'new']);
        (new AiReplyToComment($comment->id))->handle(app(MetaSendService::class));
        Http::assertNothingSent();
    }

    public function test_gpt_chat_and_summary_never_fall_back_to_claude_and_duplicate_job_is_free(): void
    {
        [$conversation, $message] = $this->conversation(); $this->gpt();
        Http::fake(['api.openai.com/*' => Http::response($this->answer()), 'graph.facebook.com/*' => Http::response(['message_id' => 'gpt-mid'])]);
        $dispatcher = app(Dispatcher::class);
        $this->assertSame('replied', $dispatcher->respond($conversation, $message->id)->status);
        $this->assertSame('replied', $dispatcher->respond($conversation, $message->id)->status);
        Http::assertSentCount(2);
        $this->assertSame('Доставка Новою поштою.', $dispatcher->summarize($conversation->fresh()));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'anthropic'));
        $this->assertDatabaseHas('ai_runs', ['provider' => 'gpt', 'model' => 'gpt-test', 'tokens_in' => 15]);
        $raw = DB::table('gpt_inbox_replies')->first()->payload;
        $this->assertStringNotContainsString('Доставка', $raw);
    }

    public function test_meta_failure_retries_saved_reply_without_another_gpt_request(): void
    {
        [$conversation, $message] = $this->conversation(); $this->gpt();
        Http::fake(['api.openai.com/*' => Http::response($this->answer()), 'graph.facebook.com/*' => Http::sequence()->push(['error' => ['message' => 'Тимчасовий збій']], 500)->push(['message_id' => 'retry-mid'])]);
        $dispatcher = app(Dispatcher::class);
        $this->assertSame('error', $dispatcher->respond($conversation, $message->id)->status);
        $this->assertSame('replied', $dispatcher->respond($conversation->fresh(), $message->id)->status);
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r->url(), 'api.openai.com')));
        $this->assertDatabaseCount('gpt_inbox_replies', 1);
        $this->assertDatabaseHas('inbox_messages', ['text' => 'Доставка Новою поштою.', 'sender' => 'ai']);
    }

    public function test_disabling_gpt_during_generation_stops_delivery_and_no_fallback_runs(): void
    {
        [$conversation, $message] = $this->conversation(); $this->gpt();
        Http::fake(function ($request) {
            $this->assertStringContainsString('api.openai.com', $request->url());
            AiChannelSetting::current()->update(['gpt_enabled' => false]);
            return Http::response($this->answer());
        });
        $this->assertSame('error', app(Dispatcher::class)->respond($conversation, $message->id)->status);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('gpt_inbox_replies', 0);
        $this->assertDatabaseMissing('inbox_messages', ['sender' => 'ai']);
    }

    public function test_switch_is_owner_only_preserves_history_and_rejects_busy_or_checkout(): void
    {
        [$conversation, $message] = $this->conversation(); $this->gpt(['claude_enabled' => true, 'default_provider' => 'claude']);
        $url = '/api/inbox/conversations/'.$conversation->id.'/ai-provider';
        $operator = User::factory()->create(['role' => 'operator']);
        $this->actingAs($operator)->postJson($url, ['provider' => 'gpt'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'owner']))->postJson($url, ['provider' => 'gpt'])->assertOk();
        $this->assertDatabaseHas('inbox_messages', ['id' => $message->id]);
        $conversation->refresh()->update(['ai_checkout_active' => true]);
        $this->postJson($url, ['provider' => 'claude'])->assertStatus(409);
        $conversation->update(['ai_checkout_active' => false]);
        $lock = Cache::lock('ai-channel-conversation-'.$conversation->id, 300); $lock->get();
        try { $this->postJson($url, ['provider' => 'claude'])->assertStatus(409); }
        finally { $lock->release(); }
        $this->postJson($url, ['provider' => 'claude'])->assertOk();
    }

    public function test_settings_hide_key_and_do_not_overwrite_claude_credentials(): void
    {
        [$conversation] = $this->conversation();
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $payload = ['claude_enabled' => false, 'gpt_enabled' => true, 'default_provider' => 'gpt', 'gpt_api_key' => 'sk-live-secret-key', 'gpt_model' => 'gpt-test'];
        $this->postJson('/settings/ai/channels', $payload)->assertOk()->assertDontSee('sk-live-secret-key');
        $this->assertSame('sk-ant-test', AiSetting::global()->api_key);
        $payload['gpt_api_key'] = null;
        $this->postJson('/settings/ai/channels', $payload)->assertOk();
        $this->assertSame('sk-live-secret-key', AiChannelSetting::current()->gpt_api_key);
        $this->assertStringNotContainsString('sk-live-secret-key', DB::table('ai_channel_settings')->value('gpt_api_key'));
        $this->actingAs(User::factory()->create(['role' => 'operator']))->postJson('/settings/ai/channels', $payload)->assertForbidden();
        $this->getJson('/settings/ai/channels')->assertForbidden();
    }

    public function test_disabled_gpt_cannot_be_called_from_models_endpoint(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => 'owner']))->postJson('/settings/ai/channels/models')->assertStatus(422);
        try { app(\App\Services\Ai\Gpt\OpenAiClient::class)->models('sk-test'); $this->fail('Вимкнений API викликано.'); }
        catch (\App\Services\Ai\Gpt\GptFailure) {}
        Http::assertNothingSent();
    }

    public function test_live_catalog_ignores_local_snapshot_media(): void
    {
        Storage::fake('local');
        $product = Product::create(['title' => 'Товар із CRM', 'is_active' => true]);
        $group = AiPhotoGroup::create(['name' => 'Робоча лінійка']); $group->products()->attach($product);
        $photo = AiPhoto::create(['ai_photo_group_id' => $group->id, 'path' => 'ai-gallery/snapshot-only-test.png']); $photo->products()->attach($product);
        Storage::disk('local')->put('gpt-sandbox/catalog-source.json', json_encode(['database' => DB::connection()->getDatabaseName()]));
        Storage::disk('local')->put('gpt-sandbox/catalog-media/'.$photo->path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF5kAAAAASUVORK5CYII='));
        $this->assertSame([], (new CatalogReader)->photos([$photo->id]));
    }

    public function test_live_checkout_is_checked_and_saved_in_existing_manager_panel(): void
    {
        [$conversation, $message] = $this->conversation(); $this->gpt();
        $product = Product::create(['title' => 'Домашні', 'sale_price' => '399.00', 'stock_qty' => 5, 'is_active' => true, 'currency' => 'UAH']);
        $args = ['items' => [['product_id' => $product->id, 'size' => null, 'qty' => 2]], 'customer_name' => 'Олена',
            'phone' => '+380671234567', 'address' => 'Київ, НП 5', 'payment' => 'cod', 'confirm_total' => false];
        $call = fn ($name, $arguments, $id) => ['status' => 'completed', 'output' => [['type' => 'function_call', 'name' => $name, 'call_id' => $id, 'arguments' => json_encode($arguments)]]];
        Http::fake(['api.openai.com/*' => Http::sequence()->push($call('update_order', $args, 'update'))
            ->push($call('complete_order', (object) [], 'complete'))->push($this->answer('Прийнято.')),
            'graph.facebook.com/*' => Http::response(['message_id' => 'order-mid'])]);
        $this->assertSame('replied', app(Dispatcher::class)->respond($conversation, $message->id)->status);
        $conversation->refresh();
        $this->assertFalse($conversation->ai_enabled);
        $this->assertFalse($conversation->ai_checkout_active);
        $this->assertSame('ready_for_manager', $conversation->ai_checkout_data['status']);
        $this->assertSame('798.00', $conversation->ai_checkout_data['total']);
        $this->assertSame('Олена', $conversation->ai_order_customer_name);
        $this->assertSame(2, $conversation->ai_order_items[0]['qty']);
        $this->assertDatabaseHas('chat_statuses', ['id' => $conversation->chat_status_id, 'code' => 'ai_order']);
        $this->assertSame(5, $product->fresh()->stock_qty);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'anthropic'));
    }

    public function test_gpt_classifies_comments_without_any_claude_request(): void
    {
        [$conversation] = $this->conversation(); $this->gpt();
        $group = AiPhotoGroup::create(['name' => 'Домашні']);
        AiSetting::global()->update(['comment_settings' => ['enabled' => true, 'facebook' => true]]);
        $comment = InboxComment::create(['meta_connection_id' => $conversation->meta_connection_id, 'channel' => 'facebook',
            'comment_id' => 'gpt-comment', 'post_id' => 'gpt-post', 'from_id' => 'U', 'text' => 'Ціна?', 'post_excerpt' => 'Домашні', 'status' => 'new']);
        Http::fake(['api.openai.com/*' => Http::response($this->answer('Домашні')), 'graph.facebook.com/*' => Http::response(['message_id' => 'comment-mid'])]);
        (new AiReplyToComment($comment->id))->handle(app(MetaSendService::class));
        $this->assertSame('dm_sent', $comment->fresh()->status);
        $this->assertSame('Домашні', $comment->fresh()->matched_group_name);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'anthropic'));
    }

    public function test_default_change_is_blocked_until_active_checkout_has_its_own_provider(): void
    {
        [$conversation] = $this->conversation(); $conversation->update(['ai_checkout_active' => true]);
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $payload = ['claude_enabled' => true, 'gpt_enabled' => true, 'default_provider' => 'gpt', 'gpt_api_key' => 'sk-test', 'gpt_model' => 'gpt-test'];
        $this->postJson('/settings/ai/channels', $payload)->assertStatus(422)->assertJsonValidationErrors('default_provider');
        $this->postJson('/api/inbox/conversations/'.$conversation->id.'/ai-provider', ['provider' => 'claude'])->assertOk();
        $this->postJson('/settings/ai/channels', $payload)->assertOk();
        $this->assertSame('claude', $conversation->fresh()->ai_provider);
    }

    public function test_history_keeps_sent_gallery_ids_and_reply_context_without_foreign_tools(): void
    {
        [$conversation] = $this->conversation();
        $product = Product::create(['title' => 'Капці чорні', 'is_active' => true]);
        $group = AiPhotoGroup::create(['name' => 'Домашні']); $group->products()->attach($product);
        $photo = AiPhoto::create(['ai_photo_group_id' => $group->id, 'path' => 'ai-gallery/trusted-note.png']); $photo->products()->attach($product);
        $conversation->messages()->create(['external_message_id' => 'photo-note-mid', 'direction' => 'out', 'sender' => 'ai', 'attachments' => [['type' => 'image', 'url' => $photo->url(), 'ai_photo_id' => $photo->id]]]);
        $conversation->messages()->create(['direction' => 'in', 'sender' => 'contact', 'text' => 'Ці беру', 'context' => ['type' => 'reply', 'mid' => 'photo-note-mid']]);
        $input = app(\App\Services\Ai\Channels\GptHistory::class)->build($conversation);
        $text = json_encode($input, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Фото товарів №'.$photo->id, $text);
        $this->assertStringContainsString('#'.$product->id, $text);
        $this->assertStringContainsString('Відповідь на повідомлення', $text);
        $this->assertStringNotContainsString('tool_use', $text);
    }

    public function test_latest_receipt_image_is_kept_when_old_messages_contain_many_images(): void
    {
        [$conversation] = $this->conversation();
        $reader = \Mockery::mock(\App\Services\Ai\Channels\GptHistory::class)->makePartial();
        $reader->shouldReceive('image')->andReturnUsing(fn ($attachment) => 'data:image/png;base64,'.$attachment['url']);
        for ($i = 0; $i < 4; $i++) {
            $conversation->messages()->create(['direction' => 'in', 'sender' => 'contact', 'text' => 'Фото '.$i,
                'attachments' => array_fill(0, 3, ['type' => 'image', 'url' => 'picture-'.$i])]);
        }
        $input = $reader->build($conversation);
        $images = collect($input)->flatMap(fn ($m) => is_array($m['content']) ? $m['content'] : [])->where('type', 'input_image');
        $this->assertCount(6, $images);
        $this->assertStringEndsWith('picture-3', $images->last()['image_url']);
        $this->assertFalse($images->contains(fn ($image) => str_ends_with($image['image_url'], 'picture-0')));
    }

    public function test_chat_uses_current_crm_default_until_owner_explicitly_pins_provider(): void
    {
        [$conversation] = $this->conversation();
        $this->assertNull($conversation->fresh()->ai_provider);
        $policy = app(\App\Services\Ai\Channels\ProviderPolicy::class);
        $this->assertSame('claude', $policy->selected($conversation));
        $this->gpt(['claude_enabled' => true]);
        $this->assertSame('gpt', $policy->selected($conversation->fresh()));
        $this->actingAs(User::factory()->create(['role' => 'owner']))
            ->getJson('/api/inbox/conversations/'.$conversation->id.'/messages')->assertOk()
            ->assertJsonPath('conversation.ai_provider', null)->assertJsonPath('conversation.ai_active_provider', 'gpt');
        $conversation->update(['ai_provider' => 'claude']);
        $this->assertSame('claude', $policy->selected($conversation->fresh()));
        $this->postJson('/api/inbox/conversations/'.$conversation->id.'/ai-provider', ['provider' => null])->assertOk();
        $this->assertNull($conversation->fresh()->ai_provider);
        $this->assertSame('gpt', $policy->selected($conversation->fresh()));
    }

    public function test_manual_off_invalidates_context_but_own_order_completion_can_send_final(): void
    {
        [$conversation] = $this->conversation();
        app(DispatchContext::class)->run('claude', $conversation, function () use ($conversation) {
            $conversation->update(['ai_enabled' => false]);
            app(DispatchContext::class)->assertCurrent();
            $conversation->increment('ai_provider_version');
            $this->expectException(ProviderUnavailable::class);
            app(DispatchContext::class)->assertCurrent();
        });
    }
}
