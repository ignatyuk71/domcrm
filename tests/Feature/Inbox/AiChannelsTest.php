<?php

namespace Tests\Feature\Inbox;

use App\Jobs\AiReplyToComment;
use App\Jobs\AiRespondToMessage;
use App\Models\AiChannelSetting;
use App\Models\AiPhoto;
use App\Models\AiPhotoGroup;
use App\Models\AiSetting;
use App\Models\GptInboxReply;
use App\Models\InboxComment;
use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\MetaConnection;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AiAgentService;
use App\Services\Ai\Channels\DispatchContext;
use App\Services\Ai\Channels\Dispatcher;
use App\Services\Ai\Channels\ProviderUnavailable;
use App\Services\Ai\Gpt\CatalogReader;
use App\Services\Meta\MetaSendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

    public function test_greetings_and_unfinished_phrases_reply_without_gpt_calls_or_handoff(): void
    {
        [$conversation, $message] = $this->conversation();
        $this->gpt();
        $sent = 0;
        Http::fake(function ($request) use (&$sent) {
            $this->assertStringContainsString('graph.facebook.com', $request->url());
            return Http::response(['message_id' => 'opening-'.(++$sent)]);
        });
        foreach (['Привіт', 'А до', 'Ви тут?'] as $index => $text) {
            if ($index === 0) $message->update(['text' => $text]);
            else $message = $conversation->messages()->create(['direction' => 'in', 'sender' => 'contact', 'text' => $text, 'sent_at' => now()]);
            $this->assertSame('replied', app(Dispatcher::class)->respond($conversation->fresh(), $message->id)->status);
            $conversation->refresh();
            $reply = $conversation->messages()->where('direction', 'out')->latest('id')->first();
            $this->assertStringContainsString('цікавить', $reply->text);
            $this->assertStringNotContainsString('уточню', $reply->text);
            $this->assertTrue($conversation->ai_enabled);
            $this->assertNull($conversation->ai_paused_until);
            $this->assertNotSame('needs_human', $conversation->ai_checkout_data['status'] ?? null);
            if ($index > 0) $this->assertStringNotContainsString('Доброго', $reply->text);
        }
        $this->assertSame(3, $sent);
        foreach (['Ціна?', '38', 'Так', 'Повернення', 'Покличте людину', 'Привіт, цікавлять тапки'] as $text) {
            $message->text = $text;
            $this->assertNull(\App\Services\Ai\Gpt\ConversationOpening::reply($conversation, $message));
        }
    }

    public function test_gpt_continues_after_its_first_reply_with_valid_assistant_history(): void
    {
        [$conversation, $message] = $this->conversation();
        $this->gpt();
        $calls = $sent = 0;
        Http::fake(function ($request) use (&$calls, &$sent) {
            if (str_contains($request->url(), 'api.openai.com')) {
                $calls++;
                foreach ($request['input'] as $item) {
                    if (($item['role'] ?? '') === 'assistant') $this->assertIsString($item['content']);
                }
                if ($calls === 2) {
                    $this->assertTrue(collect($request['input'])->contains(fn ($item) => ($item['role'] ?? '') === 'assistant' && $item['content'] === 'Доставка Новою поштою.'));
                }
                return Http::response($this->answer());
            }
            return Http::response(['message_id' => 'history-'.(++$sent)]);
        });
        $this->assertSame('replied', app(Dispatcher::class)->respond($conversation, $message->id)->status);
        $next = $conversation->messages()->create(['direction' => 'in', 'sender' => 'contact', 'text' => 'Які у вас є?', 'sent_at' => now()]);
        $this->assertSame('replied', app(Dispatcher::class)->respond($conversation->fresh(), $next->id)->status);
        $this->assertSame(2, $calls);
        $this->assertSame(2, $sent);
    }

    public function test_real_handoff_disables_gpt_until_manual_resume_without_a_timer(): void
    {
        [$conversation, $message] = $this->conversation();
        $message->update(['text' => 'Покличте менеджера']);
        $this->gpt();
        $calls = $sent = 0;
        Http::fake(function ($request) use (&$calls, &$sent) {
            if (str_contains($request->url(), 'api.openai.com')) {
                if (++$calls === 1) return Http::response(['status' => 'completed', 'output' => [['type' => 'function_call', 'name' => 'escalate_to_manager', 'call_id' => 'handoff', 'arguments' => '{}']]]);
                return Http::response($this->answer());
            }
            return Http::response(['message_id' => 'handoff-'.(++$sent)]);
        });
        $this->assertSame('replied', app(Dispatcher::class)->respond($conversation, $message->id)->status);
        $conversation->refresh();
        $this->assertFalse($conversation->ai_enabled);
        $this->assertNull($conversation->ai_paused_until);
        $this->assertSame('needs_human', $conversation->ai_checkout_data['status']);
        $this->assertArrayNotHasKey('paused_until', $conversation->ai_checkout_data);
        $this->assertSame('needs_human', $conversation->chatStatus->code);
        $this->actingAs(User::factory()->create(['role' => 'owner']))->postJson('/api/inbox/conversations/'.$conversation->id.'/ai', ['enabled' => true])->assertOk();
        $next = $conversation->messages()->create(['direction' => 'in', 'sender' => 'contact', 'text' => 'Привіт', 'sent_at' => now()]);
        $this->assertSame('replied', app(Dispatcher::class)->respond($conversation->fresh(), $next->id)->status);
        $conversation->refresh();
        $this->assertTrue($conversation->ai_enabled);
        $this->assertNull($conversation->ai_paused_until);
        $this->assertSame('draft', $conversation->ai_checkout_data['status']);
        $this->assertSame(2, $calls);
    }

    public function test_new_message_during_generation_is_processed_without_waiting_for_sweep(): void
    {
        [$conversation, $message] = $this->conversation(); $this->gpt();
        $calls = $sent = 0; $next = null;
        Http::fake(function ($request) use ($conversation, &$calls, &$sent, &$next) {
            if (str_contains($request->url(), 'api.openai.com')) {
                if (++$calls === 1) {
                    $next = $conversation->messages()->create(['direction' => 'in', 'sender' => 'contact', 'text' => 'Які є розміри?', 'sent_at' => now()]);
                    $this->assertSame('skipped_in_progress', app(Dispatcher::class)->respond($conversation->fresh(), $next->id)->status);
                    return Http::response($this->answer('Застаріла відповідь.'));
                }
                return Http::response($this->answer('Є 36/37, 38/39 та 40/41.'));
            }
            return Http::response(['message_id' => 'concurrent-'.(++$sent)]);
        });
        $this->assertSame('error', app(Dispatcher::class)->respond($conversation, $message->id)->status);
        $this->assertSame(2, $calls); $this->assertSame(1, $sent);
        $this->assertDatabaseHas('ai_runs', ['inbox_message_id' => $next->id, 'status' => 'replied']);
        $this->assertDatabaseHas('inbox_messages', ['sender' => 'ai', 'text' => 'Є 36/37, 38/39 та 40/41.']);
        $this->assertDatabaseMissing('inbox_messages', ['sender' => 'ai', 'text' => 'Застаріла відповідь.']);
    }

    public function test_message_during_photo_delivery_gets_a_reply_without_duplicate_photos(): void
    {
        [$conversation, $message] = $this->conversation(); $this->gpt();
        $group = AiPhotoGroup::create(['name' => 'Ведмедики']);
        $photo = AiPhoto::create(['ai_photo_group_id' => $group->id, 'path' => 'ai-gallery/concurrent-test.jpg']);
        $reply = GptInboxReply::create(['inbox_conversation_id' => $conversation->id, 'inbox_message_id' => $message->id, 'provider_version' => 0,
            'payload' => ['photos' => [['id' => $photo->id]], 'text' => 'Стара ціна.', 'checkout_messages' => [], 'checkout' => null]]);
        $photos = $calls = $texts = 0; $next = null;
        Http::fake(function ($request) use ($conversation, &$photos, &$calls, &$texts, &$next) {
            if (str_contains($request->url(), 'api.openai.com')) {
                $calls++;
                return Http::response($this->answer('Для обраних капців є 36/37, 38/39 та 40/41.'));
            }
            if (isset($request['message']['attachment'])) {
                $photos++;
                $next = $conversation->messages()->create(['direction' => 'in', 'sender' => 'contact', 'text' => 'Які є розміри?', 'sent_at' => now()]);
                $this->assertSame('skipped_in_progress', app(Dispatcher::class)->respond($conversation->fresh(), $next->id)->status);
                return Http::response(['message_id' => 'during-photo']);
            }
            $texts++;
            return Http::response(['message_id' => 'after-photo-text']);
        });
        $this->assertSame('skipped_stale_late', app(Dispatcher::class)->respond($conversation, $message->id)->status);
        $this->assertSame(1, $photos); $this->assertSame(1, $calls); $this->assertSame(1, $texts);
        $this->assertSame(1, $reply->fresh()->next_index);
        $this->assertDatabaseHas('ai_runs', ['inbox_message_id' => $next->id, 'status' => 'replied']);
        $this->assertDatabaseHas('inbox_messages', ['sender' => 'ai', 'text' => 'Для обраних капців є 36/37, 38/39 та 40/41.']);
        $this->assertDatabaseMissing('inbox_messages', ['sender' => 'ai', 'text' => 'Стара ціна.']);
        app(Dispatcher::class)->respond($conversation->fresh(), $message->id);
        $this->assertSame(1, $photos); $this->assertSame(1, $calls);
    }

    public function test_catalog_text_precedes_photos_and_retry_keeps_its_order_without_regeneration(): void
    {
        [$conversation, $message] = $this->conversation(); $this->gpt();
        $group = AiPhotoGroup::create(['name' => 'Ведмедики']);
        $photo = AiPhoto::create(['ai_photo_group_id' => $group->id, 'path' => 'ai-gallery/text-first-test.jpg']);
        $assistant = \Mockery::mock(\App\Services\Ai\Gpt\CatalogAssistant::class);
        $assistant->shouldReceive('reply')->once()->andReturn(['text' => 'Ведмедики — 380 грн.', 'photos' => [['id' => $photo->id]], 'checkout_messages' => [], 'checkout' => null]);
        $this->app->instance(\App\Services\Ai\Gpt\CatalogAssistant::class, $assistant);
        $order = []; $photoAttempts = 0;
        Http::fake(function ($request) use (&$order, &$photoAttempts) {
            if (isset($request['message']['attachment'])) {
                $order[] = 'photo';
                return ++$photoAttempts === 1 ? Http::response(['error' => ['message' => 'Тимчасовий збій']], 500) : Http::response(['message_id' => 'text-first-photo']);
            }
            $order[] = 'text';
            return Http::response(['message_id' => 'text-first-message']);
        });
        $this->assertSame('error', app(Dispatcher::class)->respond($conversation, $message->id)->status);
        $this->assertSame(1, GptInboxReply::first()->next_index);
        $this->assertSame('replied', app(Dispatcher::class)->respond($conversation->fresh(), $message->id)->status);
        $this->assertSame(['text', 'photo', 'photo'], $order);
        $this->assertSame(1, $conversation->messages()->where('text', 'Ведмедики — 380 грн.')->count());
        $this->assertSame(1, $conversation->messages()->whereNotNull('attachments')->count());
    }

    public function test_centimetres_answer_cannot_handoff_and_preserves_the_active_dialogue(): void
    {
        [$conversation, $message] = $this->conversation(); $this->gpt();
        $conversation->messages()->create(['direction' => 'out', 'sender' => 'ai', 'text' => 'Яка у вас довжина стопи в сантиметрах?']);
        $number = $conversation->messages()->create(['direction' => 'in', 'sender' => 'contact', 'text' => '24,6', 'sent_at' => now()]);
        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            if (str_contains($request->url(), 'api.openai.com')) {
                $this->assertStringContainsString('24.6 см', $request['instructions']);
                $this->assertFalse(collect($request['tools'])->contains('name', 'escalate_to_manager'));
                if (++$calls === 1) return Http::response(['status' => 'completed', 'output' => [['type' => 'function_call', 'name' => 'escalate_to_manager', 'call_id' => 'wrong-handoff', 'arguments' => '{}']]]);
                return Http::response($this->answer('За сіткою для 24,6 см — 38/39 (25 см).'));
            }
            return Http::response(['message_id' => 'centimetres-reply']);
        });
        $this->assertSame('replied', app(Dispatcher::class)->respond($conversation, $number->id)->status);
        $this->assertTrue($conversation->fresh()->ai_enabled);
        $this->assertNull($conversation->fresh()->ai_paused_until);
        $this->assertNotSame('needs_human', $conversation->fresh()->ai_checkout_data['status'] ?? null);
        $this->assertDatabaseHas('inbox_messages', ['sender' => 'ai', 'text' => 'За сіткою для 24,6 см — 38/39 (25 см).']);
        $this->assertSame(2, $calls);
    }

    public function test_measurement_is_kept_for_followup_advice_and_order_intent(): void
    {
        [$conversation] = $this->conversation(); $this->gpt();
        $conversation->messages()->create(['direction' => 'out', 'sender' => 'ai', 'text' => 'Яка у вас довжина стопи в сантиметрах?']);
        $conversation->messages()->create(['direction' => 'in', 'sender' => 'contact', 'text' => 'У мене 24,5', 'sent_at' => now()]);
        $advice = $conversation->messages()->create(['direction' => 'in', 'sender' => 'contact', 'text' => 'Які краще взяти?', 'sent_at' => now()]);
        $calls = $sent = 0;
        Http::fake(function ($request) use (&$calls, &$sent) {
            if (str_contains($request->url(), 'api.openai.com')) {
                $calls++;
                $this->assertStringContainsString('24.5 см', $request['instructions']);
                $this->assertStringContainsString('АКТИВНИЙ ПІДБІР І ОФОРМЛЕННЯ', $request['instructions']);
                $this->assertFalse(collect($request['tools'])->contains('name', 'escalate_to_manager'));
                return Http::response($this->answer('Орієнтир за сіткою — 38/39 (25 см). Бажаєте замовити?'));
            }
            return Http::response(['message_id' => 'advice-memory-'.(++$sent)]);
        });
        $this->assertSame('replied', app(Dispatcher::class)->respond($conversation, $advice->id)->status);
        $this->assertSame('24.5', $conversation->fresh()->ai_checkout_data['sizing']['foot_length_cm']);
        $order = $conversation->messages()->create(['direction' => 'in', 'sender' => 'contact', 'text' => 'Хочу замовити 36', 'sent_at' => now()]);
        $this->assertSame('replied', app(Dispatcher::class)->respond($conversation->fresh(), $order->id)->status);
        $this->assertSame(2, $calls);
        $this->assertTrue($conversation->fresh()->ai_enabled);
        $this->assertNotSame('needs_human', $conversation->fresh()->ai_checkout_data['status']);
        $human = $conversation->messages()->create(['direction' => 'in', 'sender' => 'contact', 'text' => 'Покличте менеджера', 'sent_at' => now()]);
        $this->assertNull(\App\Services\Ai\Gpt\ConversationOpening::footLength($conversation->fresh(), $human));
        $this->assertFalse(\App\Services\Ai\Gpt\ConversationOpening::isAdvice($human));
    }

    public function test_gpt6_requests_use_low_reasoning_for_messenger_latency(): void
    {
        [$conversation, $message] = $this->conversation();
        $this->gpt(['gpt_model' => 'gpt-6.1-sol']);
        Http::fake(['api.openai.com/*' => function ($request) {
            $this->assertSame('low', $request['reasoning']['effort']);
            return Http::response($this->answer());
        }, 'graph.facebook.com/*' => Http::response(['message_id' => 'low-reasoning'])]);
        $this->assertSame('replied', app(Dispatcher::class)->respond($conversation, $message->id)->status);
    }

    public function test_gpt_chat_and_summary_never_fall_back_to_claude_and_duplicate_job_is_free(): void
    {
        [$conversation, $message] = $this->conversation();
        $this->gpt();
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
        [$conversation, $message] = $this->conversation();
        $this->gpt();
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
        [$conversation, $message] = $this->conversation();
        $this->gpt();
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

    public function test_chat_provider_control_and_old_endpoint_are_removed(): void
    {
        [$conversation] = $this->conversation();
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $this->get('/inbox')->assertOk()->assertDontSee('chat-ai-provider')->assertDontSee('Канал цього чату');
        $this->postJson('/api/inbox/conversations/'.$conversation->id.'/ai-provider', ['provider' => 'gpt'])->assertNotFound();
    }

    public function test_settings_hide_key_and_do_not_overwrite_claude_credentials(): void
    {
        [$conversation] = $this->conversation();
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $payload = ['model' => 'claude-sonnet-4-6', 'agent' => 'gpt', 'gpt_api_key' => 'sk-live-secret-key', 'gpt_model' => 'gpt-test'];
        $this->postJson('/settings/ai/save', $payload)->assertOk()->assertDontSee('sk-live-secret-key');
        $this->assertSame('sk-ant-test', AiSetting::global()->api_key);
        $payload['gpt_api_key'] = null;
        $this->postJson('/settings/ai/save', $payload)->assertOk();
        $this->assertSame('sk-live-secret-key', AiChannelSetting::current()->gpt_api_key);
        $this->assertStringNotContainsString('sk-live-secret-key', DB::table('ai_channel_settings')->value('gpt_api_key'));
        $this->actingAs(User::factory()->create(['role' => 'operator']))->postJson('/settings/ai/save', $payload)->assertForbidden();
        $this->getJson('/settings/ai/data')->assertForbidden();
    }

    public function test_disabled_gpt_cannot_be_called_from_models_endpoint(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => 'owner']))->postJson('/settings/ai/channels/models')->assertNotFound();
        try {
            app(\App\Services\Ai\Gpt\OpenAiClient::class)->models('sk-test');
            $this->fail('Вимкнений API викликано.');
        } catch (\App\Services\Ai\Gpt\GptFailure) {
        }
        Http::assertNothingSent();
    }

    public function test_live_catalog_ignores_local_snapshot_media(): void
    {
        Storage::fake('local');
        $product = Product::create(['title' => 'Товар із CRM', 'is_active' => true]);
        $group = AiPhotoGroup::create(['name' => 'Робоча лінійка']);
        $group->products()->attach($product);
        $photo = AiPhoto::create(['ai_photo_group_id' => $group->id, 'path' => 'ai-gallery/snapshot-only-test.png']);
        $photo->products()->attach($product);
        Storage::disk('local')->put('gpt-sandbox/catalog-source.json', json_encode(['database' => DB::connection()->getDatabaseName()]));
        Storage::disk('local')->put('gpt-sandbox/catalog-media/'.$photo->path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF5kAAAAASUVORK5CYII='));
        $this->assertSame([], (new CatalogReader)->photos([$photo->id]));
    }

    public function test_live_checkout_is_checked_and_saved_in_existing_manager_panel(): void
    {
        [$conversation, $message] = $this->conversation();
        $this->gpt();
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
        [$conversation] = $this->conversation();
        $this->gpt();
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

    public function test_one_api_choice_switches_all_chats_without_losing_an_active_checkout(): void
    {
        [$conversation] = $this->conversation();
        $draft = ['status' => 'draft', 'items' => [['product_id' => 42, 'qty' => 1]]];
        $conversation->update(['ai_checkout_active' => true, 'ai_checkout_data' => $draft]);
        DB::table('inbox_conversations')->where('id', $conversation->id)->update(['ai_provider' => 'claude']);
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Http::fake();
        $this->postJson('/settings/ai/save', ['model' => 'claude-sonnet-4-6', 'agent' => 'gpt', 'gpt_api_key' => 'sk-test', 'gpt_model' => 'gpt-test'])->assertOk();
        $setting = AiChannelSetting::current();
        $this->assertTrue($setting->gpt_enabled);
        $this->assertFalse($setting->claude_enabled);
        $this->assertSame('gpt', $setting->default_provider);
        $this->assertSame($draft, $conversation->fresh()->ai_checkout_data);
        $this->assertTrue($conversation->fresh()->ai_checkout_active);
        $this->getJson('/settings/ai/data')->assertJsonPath('connection.agent', 'gpt');

        $this->postJson('/settings/ai/save', ['model' => 'claude-sonnet-4-6', 'agent' => 'claude'])->assertOk();
        $this->assertTrue(AiChannelSetting::current()->claude_enabled);
        $this->assertFalse(AiChannelSetting::current()->gpt_enabled);
        $this->assertSame('sk-test', AiChannelSetting::current()->gpt_api_key);
        $this->assertSame('gpt-test', AiChannelSetting::current()->gpt_model);
        Http::assertNothingSent();
    }

    public function test_off_stops_both_apis_and_preserves_credentials(): void
    {
        [$conversation, $message] = $this->conversation();
        $this->gpt();
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Http::fake();
        $this->postJson('/settings/ai/save', ['model' => 'claude-sonnet-4-6', 'agent' => 'off'])->assertOk();
        $this->assertFalse(AiChannelSetting::current()->claude_enabled);
        $this->assertFalse(AiChannelSetting::current()->gpt_enabled);
        $this->getJson('/settings/ai/data')->assertJsonPath('connection.agent', 'off');
        $this->assertSame('sk-test-live-key', AiChannelSetting::current()->gpt_api_key);
        $this->assertSame('sk-ant-test', AiSetting::global()->api_key);
        $this->assertSame('skipped_api_off', app(Dispatcher::class)->respond($conversation, $message->id)->status);
        Http::assertNothingSent();
    }

    public function test_missing_gpt_credentials_rejects_the_whole_save_and_preserves_claude(): void
    {
        $this->conversation();
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Http::fake();
        $this->postJson('/settings/ai/save', ['model' => 'claude-changed', 'agent' => 'gpt'])->assertUnprocessable()
            ->assertJsonValidationErrors(['gpt_api_key', 'gpt_model']);
        $this->assertTrue(AiChannelSetting::current()->claude_enabled);
        $this->assertFalse(AiChannelSetting::current()->gpt_enabled);
        $this->assertSame('claude-sonnet-4-6', AiSetting::global()->model);
        Http::assertNothingSent();
    }

    public function test_history_keeps_sent_gallery_ids_and_reply_context_without_foreign_tools(): void
    {
        [$conversation] = $this->conversation();
        $product = Product::create(['title' => 'Капці чорні', 'is_active' => true]);
        $group = AiPhotoGroup::create(['name' => 'Домашні']);
        $group->products()->attach($product);
        $photo = AiPhoto::create(['ai_photo_group_id' => $group->id, 'path' => 'ai-gallery/trusted-note.png']);
        $photo->products()->attach($product);
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

    public function test_all_chats_follow_crm_provider_including_legacy_chat_overrides(): void
    {
        [$conversation] = $this->conversation();
        DB::table('inbox_conversations')->where('id', $conversation->id)->update(['ai_provider' => 'claude']);
        $policy = app(\App\Services\Ai\Channels\ProviderPolicy::class);
        $this->assertSame('claude', $policy->selected());
        $this->gpt(['claude_enabled' => true]);
        $this->assertSame('gpt', $policy->selected());
        $this->actingAs(User::factory()->create(['role' => 'owner']))
            ->getJson('/api/inbox/conversations/'.$conversation->id.'/messages')->assertOk()
            ->assertJsonMissingPath('conversation.ai_provider')->assertJsonPath('conversation.ai_active_provider', 'gpt');
        AiChannelSetting::current()->update(['default_provider' => 'claude']);
        $this->assertSame('claude', $policy->selected());
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
