<?php

namespace Tests\Feature\Inbox;

use App\Models\{AiChannelSetting, AiPhoto, AiPhotoGroup, AiSetting, GptInboxReply, InboxContact, InboxConversation, InboxMessage, MetaConnection};
use App\Services\Ai\Channels\Dispatcher;
use App\Services\Ai\Gpt\{CatalogAssistant, DeliveryImage};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GptImageDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 12:00:00.250000');
        Http::preventStrayRequests();
        AiChannelSetting::current()->update(['default_provider' => 'gpt', 'gpt_enabled' => true, 'claude_enabled' => false,
            'gpt_api_key' => 'sk-test-image-delivery', 'gpt_model' => 'gpt-test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_new_response_sends_all_three_photos_before_its_text(): void
    {
        [$conversation, $message] = $this->conversation();
        $photos = $this->photos();
        $assistant = \Mockery::mock(CatalogAssistant::class);
        $assistant->shouldReceive('reply')->once()->andReturn($this->payload($photos));
        $this->app->instance(CatalogAssistant::class, $assistant);
        $order = [];
        Http::fake(['graph.facebook.com/*' => function ($request) use (&$order) {
            $order[] = isset($request['message']['attachment']) ? 'photo' : 'text';

            return Http::response(['message_id' => 'image-first-'.count($order)]);
        }]);

        $run = app(Dispatcher::class)->respond($conversation, $message->id);

        $this->assertSame('replied', $run->status);
        $this->assertSame(['photo', 'photo', 'photo', 'text'], $order);
        $reply = GptInboxReply::firstOrFail();
        $this->assertFalse($reply->payload['text_first']);
        $this->assertSame(4, $reply->next_index);
        $this->assertSame('sent', $reply->status);
        $sent = $conversation->messages()->where('sender', 'ai')->orderBy('id')->get();
        $this->assertSame(array_column($photos, 'id'), $sent->take(3)->map(fn ($row) => $row->attachments[0]['ai_photo_id'])->all());
        $this->assertSame('Ведмедики — 380 грн. Який колір вас цікавить?', $sent->last()->text);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'openai') || str_contains($request->url(), 'anthropic'));
    }

    public function test_failed_second_photo_prevents_text_and_retry_resumes_without_new_gpt_or_duplicate_first_photo(): void
    {
        [$conversation, $message] = $this->conversation();
        $photos = $this->photos();
        $assistant = \Mockery::mock(CatalogAssistant::class);
        $assistant->shouldReceive('reply')->once()->andReturn($this->payload($photos));
        $this->app->instance(CatalogAssistant::class, $assistant);
        $order = [];
        Http::fake(['graph.facebook.com/*' => function ($request) use (&$order) {
            $photo = $request['message']['attachment']['payload']['url'] ?? null;
            $order[] = $photo ?? 'text';
            if (count($order) === 2) return Http::response(['error' => ['message' => 'Тимчасова помилка другого фото']], 500);

            return Http::response(['message_id' => 'photo-retry-'.count($order)]);
        }]);
        $dispatcher = app(Dispatcher::class);

        $this->assertSame('error', $dispatcher->respond($conversation, $message->id)->status);
        $this->assertCount(2, $order);
        $this->assertNotContains('text', $order);
        $this->assertSame(1, GptInboxReply::firstOrFail()->next_index);
        $this->assertSame(1, $conversation->messages()->where('sender', 'ai')->count());

        $this->assertSame('replied', $dispatcher->respond($conversation->fresh(), $message->id)->status);
        $this->assertCount(5, $order);
        $this->assertSame($order[1], $order[2]);
        $this->assertSame('text', $order[4]);
        $sent = $conversation->messages()->where('sender', 'ai')->orderBy('id')->get();
        $this->assertSame(array_column($photos, 'id'), $sent->take(3)->map(fn ($row) => $row->attachments[0]['ai_photo_id'])->all());
        $this->assertSame(4, GptInboxReply::firstOrFail()->next_index);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'openai') || str_contains($request->url(), 'anthropic'));
    }

    public function test_optimized_url_is_sent_and_persisted_with_original_photo_id_before_text(): void
    {
        [$conversation, $message] = $this->conversation();
        $photos = array_slice($this->photos(), 0, 1);
        $originalId = $photos[0]['id'];
        $optimizedUrl = url('ai-gallery/gpt-delivery/fake.jpg');
        $images = \Mockery::mock(DeliveryImage::class);
        $images->shouldReceive('url')->once()->with(\Mockery::on(fn ($photo) => $photo instanceof AiPhoto && $photo->id === $originalId))
            ->andReturn($optimizedUrl);
        $this->app->instance(DeliveryImage::class, $images);
        $assistant = \Mockery::mock(CatalogAssistant::class);
        $assistant->shouldReceive('reply')->once()->andReturn($this->payload($photos));
        $this->app->instance(CatalogAssistant::class, $assistant);
        $order = [];
        Http::fake(['graph.facebook.com/*' => function ($request) use (&$order, $optimizedUrl) {
            $photo = $request['message']['attachment'] ?? null;
            $order[] = $photo ? 'photo' : 'text';
            if ($photo) $this->assertSame($optimizedUrl, $photo['payload']['url']);

            return Http::response(['message_id' => 'optimized-'.count($order)]);
        }]);

        $this->assertSame('replied', app(Dispatcher::class)->respond($conversation, $message->id)->status);
        $this->assertSame(['photo', 'text'], $order);
        $sent = $conversation->messages()->where('sender', 'ai')->whereNotNull('attachments')->firstOrFail();
        $this->assertSame([['type' => 'image', 'url' => $optimizedUrl, 'ai_photo_id' => $originalId]], $sent->attachments);
        $this->assertNotSame(AiPhoto::findOrFail($originalId)->url(), $sent->attachments[0]['url']);
        $this->assertSame($photos, GptInboxReply::firstOrFail()->payload['photos']);
    }

    public function test_old_text_first_payload_with_cursor_one_is_not_reordered_or_regenerated(): void
    {
        [$conversation, $message] = $this->conversation();
        $photos = $this->photos();
        $payload = $this->payload($photos) + ['text_first' => true];
        $conversation->messages()->create(['direction' => 'out', 'sender' => 'ai', 'text' => $payload['text'],
            'external_message_id' => 'already-sent-text', 'sent_at' => now()]);
        $reply = GptInboxReply::create(['inbox_conversation_id' => $conversation->id, 'inbox_message_id' => $message->id,
            'provider_version' => (int) $conversation->ai_provider_version, 'payload' => $payload, 'next_index' => 1]);
        $assistant = \Mockery::mock(CatalogAssistant::class);
        $assistant->shouldNotReceive('reply');
        $this->app->instance(CatalogAssistant::class, $assistant);
        $order = [];
        Http::fake(['graph.facebook.com/*' => function ($request) use (&$order) {
            $order[] = isset($request['message']['attachment']) ? 'photo' : 'text';

            return Http::response(['message_id' => 'legacy-photo-'.count($order)]);
        }]);

        $this->assertSame('replied', app(Dispatcher::class)->respond($conversation, $message->id)->status);
        $this->assertSame(['photo', 'photo', 'photo'], $order);
        $this->assertTrue($reply->fresh()->payload['text_first']);
        $this->assertSame(4, $reply->fresh()->next_index);
        $this->assertSame(1, $conversation->messages()->where('sender', 'ai')->where('text', $payload['text'])->count());
    }

    public function test_new_incoming_between_photos_stops_obsolete_photos_and_text_then_gets_a_reply(): void
    {
        [$conversation, $message] = $this->conversation();
        $photos = $this->photos();
        $calls = 0;
        $assistant = \Mockery::mock(CatalogAssistant::class);
        $assistant->shouldReceive('reply')->twice()->andReturnUsing(function (...$arguments) use (&$calls, $photos) {
            if (++$calls === 1) return $this->payload($photos);
            $this->assertContains($photos[0]['id'], $arguments[5]);

            return $this->payload([], 'Є розмір 38/39.');
        });
        $this->app->instance(CatalogAssistant::class, $assistant);
        $order = [];
        $next = null;
        Http::fake(['graph.facebook.com/*' => function ($request) use ($conversation, &$order, &$next) {
            $photo = isset($request['message']['attachment']);
            $order[] = $photo ? 'photo' : ($request['message']['text'] ?? '');
            if ($photo) {
                $next = $this->incoming($conversation, 'А є розмір 38/39?');
            }

            return Http::response(['message_id' => 'new-between-photos-'.count($order)]);
        }]);

        $this->assertSame('skipped_stale_late', app(Dispatcher::class)->respond($conversation, $message->id)->status);
        $this->assertSame(['photo', 'Є розмір 38/39.'], $order);
        $this->assertSame(2, $calls);
        $this->assertDatabaseHas('gpt_inbox_replies', ['inbox_message_id' => $message->id, 'next_index' => 1]);
        $this->assertDatabaseHas('ai_runs', ['inbox_message_id' => $next->id, 'status' => 'replied']);
        $this->assertSame(0, $conversation->messages()->where('text', $this->payload($photos)['text'])->count());
    }

    public function test_operator_reply_during_first_photo_stops_remaining_bot_messages(): void
    {
        [$conversation, $message] = $this->conversation();
        $assistant = \Mockery::mock(CatalogAssistant::class);
        $assistant->shouldReceive('reply')->once()->andReturn($this->payload($this->photos()));
        $this->app->instance(CatalogAssistant::class, $assistant);
        $order = [];
        Http::fake(['graph.facebook.com/*' => function ($request) use ($conversation, &$order) {
            $order[] = isset($request['message']['attachment']) ? 'photo' : 'text';
            $conversation->messages()->create(['direction' => 'out', 'sender' => 'agent', 'text' => 'Допоможу вам особисто.', 'sent_at' => now()]);

            return Http::response(['message_id' => 'operator-during-photo']);
        }]);

        $this->assertSame('skipped_stale_late', app(Dispatcher::class)->respond($conversation, $message->id)->status);
        $this->assertSame(['photo'], $order);
    }

    public function test_disabling_gpt_during_first_photo_blocks_remaining_photos_and_text(): void
    {
        [$conversation, $message] = $this->conversation();
        $assistant = \Mockery::mock(CatalogAssistant::class);
        $assistant->shouldReceive('reply')->once()->andReturn($this->payload($this->photos()));
        $this->app->instance(CatalogAssistant::class, $assistant);
        $order = [];
        Http::fake(['graph.facebook.com/*' => function ($request) use (&$order) {
            $order[] = isset($request['message']['attachment']) ? 'photo' : 'text';
            AiChannelSetting::current()->update(['gpt_enabled' => false]);

            return Http::response(['message_id' => 'disabled-during-photo']);
        }]);

        $this->assertSame('error', app(Dispatcher::class)->respond($conversation, $message->id)->status);
        $this->assertSame(['photo'], $order);
        $this->assertSame(1, GptInboxReply::firstOrFail()->next_index);
    }

    private function payload(array $photos, string $text = 'Ведмедики — 380 грн. Який колір вас цікавить?'): array
    {
        return ['text' => $text, 'photos' => $photos, 'checkout_messages' => [], 'checkout' => null, 'tokens_in' => 15, 'tokens_out' => 5];
    }

    private function conversation(): array
    {
        $connection = MetaConnection::create(['page_id' => 'IMAGE-DELIVERY-PAGE', 'page_name' => 'Магазин', 'page_access_token' => 'test-token', 'status' => 'active']);
        AiSetting::forConnection($connection->id)->update(['enabled' => true, 'system_prompt' => 'Короткі відповіді.']);
        $contact = InboxContact::create(['meta_connection_id' => $connection->id, 'channel' => 'facebook', 'external_id' => 'IMAGE-DELIVERY-USER']);
        $conversation = InboxConversation::create(['meta_connection_id' => $connection->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook', 'ai_enabled' => true]);

        return [$conversation, $this->incoming($conversation, 'Покажіть фото і ціну.')];
    }

    private function incoming(InboxConversation $conversation, string $text): InboxMessage
    {
        $message = $conversation->messages()->create(['direction' => 'in', 'sender' => 'contact', 'text' => $text, 'sent_at' => now()]);
        return $message;
    }

    private function photos(): array
    {
        $group = AiPhotoGroup::create(['name' => 'Ведмедики']);

        return array_map(fn ($index) => ['id' => AiPhoto::create(['ai_photo_group_id' => $group->id,
            'path' => 'ai-gallery/image-delivery-'.$index.'.jpg'])->id], range(1, 3));
    }
}
