<?php

namespace Tests\Feature\Inbox;

use App\Models\{AiChannelSetting, AiRun, AiSetting, InboxComment, InboxContact, InboxConversation, InboxMessage, MetaConnection, Product};
use App\Services\Ai\Gpt\{CommentContextStore, CommentPostContext, CommentResponder};
use App\Services\Ai\OutgoingMessageWriter;
use App\Services\Meta\MetaSendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Cache, DB, File, Http};
use Tests\TestCase;

class GptCommentContextStoreTest extends TestCase
{
    use RefreshDatabase;

    private MetaConnection $connection;
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-02 12:00:00'));
        Http::preventStrayRequests();
        $this->connection = MetaConnection::create(['page_id' => 'CONTEXT_PAGE', 'page_name' => 'Магазин',
            'page_access_token' => 'test-token', 'status' => 'active']);
        $this->fixtureRoot = sys_get_temp_dir().'/gpt-context-store-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->fixtureRoot.'/inbox-context');
        $this->app->usePublicPath($this->fixtureRoot);
        File::put($this->fixtureRoot.'/inbox-context/product.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFklEQVQImWPUqvJlYGBgYmBgYGBgAAALUwD1s8HsnAAAAABJRU5ErkJggg=='));
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        File::deleteDirectory($this->fixtureRoot);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function comment(array $changes = []): InboxComment
    {
        return InboxComment::create(array_replace(['meta_connection_id' => $this->connection->id, 'channel' => 'facebook',
            'post_id' => 'POST_1', 'post_excerpt' => 'Домашні капці', 'post_image' => 'inbox-context/product.png',
            'comment_id' => 'platform-'.bin2hex(random_bytes(4)), 'from_id' => 'CUSTOMER', 'from_name' => 'Олена',
            'text' => 'Ціна рожевих?', 'status' => 'new', 'commented_at' => now()], $changes));
    }

    private function conversation(InboxComment $comment, array $changes = []): InboxConversation
    {
        $contact = InboxContact::firstOrCreate(['meta_connection_id' => $comment->meta_connection_id,
            'channel' => $comment->channel, 'external_id' => $comment->from_id]);
        return InboxConversation::create(array_replace(['meta_connection_id' => $comment->meta_connection_id,
            'channel' => $comment->channel, 'inbox_contact_id' => $contact->id, 'ai_enabled' => true], $changes));
    }

    private function capture(InboxComment $comment, array $post = [], array $decision = [], array $result = []): void
    {
        $product = Product::firstOrCreate(['title' => 'Рожеві домашні'], ['sale_price' => '380.00', 'is_active' => true, 'stock_qty' => 5]);
        app(CommentContextStore::class)->capture($comment, array_replace(['text' => 'Повний підпис допису',
            'image_paths' => ['inbox-context/product.png'], 'post_url' => 'https://www.facebook.com/reel/12345'], $post),
            array_replace(['intent' => 'price', 'product_ids' => [$product->id], 'confidence' => 'clear',
                'has_color' => true, 'has_size' => false], $decision),
            [['id' => $product->id, 'title' => $product->title, 'color' => 'Рожевий', 'price' => '380.00', 'available' => true]],
            array_replace(['text' => 'Рожеві домашні — 380 грн. Який розмір потрібен?', 'handoff' => false], $result));
    }

    public function test_capture_is_encrypted_bounded_and_does_not_create_a_phantom_conversation(): void
    {
        $comment = $this->comment();
        $this->capture($comment, ['text' => str_repeat('А', 4500), 'image_paths' => ['../.env', 'inbox-context/product.png', 'inbox-context/product.png'],
            'post_url' => 'javascript:alert(1)']);
        $raw = $comment->fresh()->gpt_context;
        $this->assertSame('prepared', $raw['delivery_status']);
        $this->assertSame($comment->id, $raw['comment_id']);
        $this->assertSame('CUSTOMER', $raw['from_id']);
        $this->assertSame(4000, mb_strlen($raw['post_text']));
        $this->assertSame(['inbox-context/product.png'], $raw['image_paths']);
        $this->assertNull($raw['post_url']);
        $this->assertSame('380.00', $raw['products'][0]['quoted_price']);
        $this->assertSame('Рожевий', $raw['products'][0]['color']);
        $this->assertSame(5, count($raw['decision']));
        $this->assertStringNotContainsString('base64', json_encode($raw));
        $this->assertStringNotContainsString('Рожеві домашні', DB::table('inbox_comments')->where('id', $comment->id)->value('gpt_context'));
        $this->assertArrayNotHasKey('gpt_context', $comment->fresh()->toArray());
        $this->assertNull($comment->fresh()->inbox_conversation_id);
        $this->assertDatabaseCount('inbox_contacts', 0);
        $this->assertDatabaseCount('inbox_conversations', 0);
    }

    public function test_successful_dm_is_linked_and_persisted_without_waiting_for_meta_echo(): void
    {
        $comment = $this->comment();
        $this->capture($comment);
        $store = app(CommentContextStore::class);
        $conversation = $store->sent($comment, 'missing-echo-mid');
        $this->assertNotNull($conversation);
        $this->assertSame('CUSTOMER', $conversation->contact->external_id);
        $this->assertSame($conversation->id, $comment->fresh()->inbox_conversation_id);
        $this->assertSame('dm_sent', $comment->fresh()->status);
        $message = $conversation->messages()->sole();
        $this->assertSame('ai', $message->sender);
        $this->assertSame('missing-echo-mid', $message->external_message_id);
        $this->assertSame('gpt_comment', $message->context['type']);
        $this->assertSame($comment->id, $message->context['comment_id']);
        $this->assertSame($message->id, $store->source($conversation)['_message_id']);
        $this->assertSame('sent', $store->source($conversation)['delivery_status']);
        $store->sent($comment, 'missing-echo-mid');
        $this->assertSame(1, $conversation->messages()->count());
    }

    public function test_echo_before_api_return_keeps_metadata_and_removes_only_its_false_operator_pause(): void
    {
        AiSetting::global()->update(['operator_pause_minutes' => 3]);
        $comment = $this->comment();
        $conversation = $this->conversation($comment, ['ai_paused_until' => now()->addMinutes(3)]);
        $echo = $conversation->messages()->create(['direction' => 'out', 'sender' => 'agent', 'external_message_id' => 'echo-first',
            'text' => 'Текст echo', 'context' => ['echo_marker' => 'kept'], 'sent_at' => now()]);
        $this->capture($comment);
        app(CommentContextStore::class)->sent($comment, 'echo-first');

        $this->assertSame(1, $conversation->messages()->count());
        $echo->refresh();
        $this->assertSame('ai', $echo->sender);
        $this->assertSame('sent', $echo->context['delivery_status']);
        $this->assertSame('kept', $echo->context['echo_marker']);
        $this->assertSame($comment->id, $echo->context['comment_id']);
        $this->assertTrue($conversation->fresh()->ai_enabled);
        $this->assertNull($conversation->fresh()->ai_paused_until);
    }

    public function test_real_operator_message_prevents_clearing_a_matching_pause(): void
    {
        AiSetting::global()->update(['operator_pause_minutes' => 3]);
        $comment = $this->comment();
        $conversation = $this->conversation($comment, ['ai_paused_until' => now()->addMinutes(3)]);
        $conversation->messages()->create(['direction' => 'out', 'sender' => 'agent', 'text' => 'Я вже допомагаю клієнтці.', 'sent_at' => now()]);
        $conversation->messages()->create(['direction' => 'out', 'sender' => 'agent', 'external_message_id' => 'echo-with-human',
            'text' => 'Текст echo', 'sent_at' => now()]);
        $pause = $conversation->fresh()->ai_paused_until->toIso8601String();
        $this->capture($comment);
        app(CommentContextStore::class)->sent($comment, 'echo-with-human');

        $this->assertSame($pause, $conversation->fresh()->ai_paused_until->toIso8601String());
        $this->assertSame(1, $conversation->messages()->where('sender', 'agent')->count());
    }

    public function test_explicit_manual_pause_is_preserved_without_any_operator_message(): void
    {
        $comment = $this->comment();
        $conversation = $this->conversation($comment, ['ai_paused_until' => now()->addMinutes(15)]);
        $conversation->messages()->create(['direction' => 'out', 'sender' => 'agent', 'external_message_id' => 'echo-manual-pause',
            'text' => 'Текст echo', 'sent_at' => now()]);
        $pause = $conversation->fresh()->ai_paused_until->toIso8601String();
        $this->capture($comment);
        app(CommentContextStore::class)->sent($comment, 'echo-manual-pause');
        $this->assertSame($pause, $conversation->fresh()->ai_paused_until->toIso8601String());
    }

    public function test_multiple_posts_keep_distinct_snapshots_and_explicit_reply_wins_over_latest(): void
    {
        $first = $this->comment();
        $this->capture($first, ['text' => 'Перший допис']);
        $store = app(CommentContextStore::class);
        $conversation = $store->sent($first, 'first-post-dm');
        $second = $this->comment(['post_id' => 'POST_2']);
        $this->capture($second, ['text' => 'Другий допис']);
        $store->sent($second, 'second-post-dm');
        $this->assertSame('POST_2', $store->source($conversation)['post_id']);
        $quoted = $conversation->messages()->create(['direction' => 'in', 'sender' => 'contact', 'text' => 'Ось ці',
            'context' => ['type' => 'reply', 'mid' => 'first-post-dm'], 'sent_at' => now()]);
        $conversation->messages()->create(['direction' => 'in', 'sender' => 'contact', 'text' => '38', 'sent_at' => now()]);
        $this->assertSame('POST_1', $store->source($conversation, $quoted)['post_id']);
        $this->assertSame('POST_1', $store->source($conversation)['post_id']);
        $this->assertSame('Перший допис', $first->fresh()->gpt_context['post_text']);
        $this->assertSame('Другий допис', $second->fresh()->gpt_context['post_text']);
    }

    public function test_handled_old_quote_does_not_override_a_new_plain_inbound_burst(): void
    {
        $first = $this->comment();
        $this->capture($first);
        $store = app(CommentContextStore::class);
        $conversation = $store->sent($first, 'old-post-dm');
        $second = $this->comment(['post_id' => 'POST_2']);
        $this->capture($second);
        $store->sent($second, 'new-post-dm');
        $old = $conversation->messages()->create(['direction' => 'in', 'sender' => 'contact', 'text' => 'До першого',
            'context' => ['type' => 'reply', 'mid' => 'old-post-dm'], 'sent_at' => now()]);
        AiRun::create(['inbox_conversation_id' => $conversation->id, 'inbox_message_id' => $old->id, 'status' => 'replied']);
        $conversation->messages()->create(['direction' => 'in', 'sender' => 'contact', 'text' => 'Є ще кольори?', 'sent_at' => now()]);
        $this->assertSame('POST_2', $store->source($conversation)['post_id']);
    }

    public function test_reset_blocks_old_sources_and_quoted_old_dm_cannot_restore_them(): void
    {
        $comment = $this->comment();
        $this->capture($comment);
        $store = app(CommentContextStore::class);
        $conversation = $store->sent($comment, 'before-reset');
        $message = $conversation->messages()->sole();
        $conversation->update(['ai_context_after_id' => $message->id]);
        $trigger = $conversation->messages()->create(['direction' => 'in', 'sender' => 'contact', 'text' => 'Такі',
            'context' => ['type' => 'reply', 'mid' => 'before-reset'], 'sent_at' => now()]);
        $this->assertNull($store->source($conversation, $trigger));
        $this->assertNull($store->source($conversation));
    }

    public function test_failed_skipped_and_prepared_snapshots_do_not_become_active_sources(): void
    {
        $store = app(CommentContextStore::class);
        foreach (['prepared', 'failed', 'skipped'] as $state) {
            $comment = $this->comment(['from_id' => 'CUSTOMER_'.$state]);
            $conversation = $this->conversation($comment);
            $this->capture($comment);
            if ($state === 'failed') $store->failed($comment, 'Не доставлено');
            if ($state === 'skipped') $store->skipped($comment, 'contact_cooldown');
            $conversation->messages()->create(['direction' => 'out', 'sender' => 'ai', 'text' => 'Недоставлений текст',
                'context' => $comment->fresh()->gpt_context, 'sent_at' => now()]);
            $this->assertNull($store->source($conversation));
            $this->assertSame($state, $comment->fresh()->gpt_context['delivery_status']);
        }
    }

    public function test_failed_and_praise_results_keep_snapshot_without_creating_contacts_or_chats(): void
    {
        $store = app(CommentContextStore::class);
        $failed = $this->comment();
        $this->capture($failed);
        $store->failed($failed, 'Meta тимчасово недоступна');
        $praise = $this->comment(['text' => 'Дякую!']);
        $this->capture($praise, [], ['intent' => 'praise', 'product_ids' => [], 'confidence' => 'clear'], ['text' => '']);
        $store->skipped($praise, 'praise');
        $this->assertSame('failed', $failed->fresh()->gpt_context['delivery_status']);
        $this->assertSame('Meta тимчасово недоступна', $failed->fresh()->gpt_context['error']);
        $this->assertSame('praise', $praise->fresh()->gpt_context['skip_reason']);
        $this->assertSame([], $praise->fresh()->gpt_context['products']);
        $this->assertDatabaseCount('inbox_contacts', 0);
        $this->assertDatabaseCount('inbox_conversations', 0);
    }

    public function test_context_remains_available_from_sent_message_after_comment_is_deleted(): void
    {
        $comment = $this->comment();
        $this->capture($comment);
        $store = app(CommentContextStore::class);
        $conversation = $store->sent($comment, 'deleted-comment-dm');
        $id = $comment->id;
        $comment->delete();
        $this->assertSame($id, $store->source($conversation)['comment_id']);
        $this->assertSame('POST_1', $store->source($conversation)['post_id']);
    }

    public function test_legacy_source_uses_exact_dm_mid_and_never_reanalyses_or_modifies_the_comment(): void
    {
        $comment = $this->comment(['status' => 'dm_sent', 'dm_message_id' => 'legacy-mid']);
        $conversation = $this->conversation($comment);
        $message = $conversation->messages()->create(['direction' => 'out', 'sender' => 'agent',
            'external_message_id' => 'legacy-mid', 'text' => 'Історична відповідь', 'sent_at' => now()]);
        $source = app(CommentContextStore::class)->source($conversation);
        $this->assertSame($message->id, $source['_message_id']);
        $this->assertTrue($source['legacy']);
        $this->assertSame('Домашні капці', $source['post_text']);
        $this->assertSame([], $source['products']);
        $this->assertSame([], $source['decision']['product_ids']);
        $this->assertNull($comment->fresh()->gpt_context);
        $this->assertNull($comment->fresh()->inbox_conversation_id);
        $conversation->update(['ai_context_after_id' => $message->id]);
        $this->assertNull(app(CommentContextStore::class)->source($conversation));
    }

    public function test_source_cannot_cross_author_store_or_channel_even_with_copied_metadata(): void
    {
        $comment = $this->comment();
        $this->capture($comment);
        $store = app(CommentContextStore::class);
        $original = $store->sent($comment, 'original-mid');
        $raw = $original->messages()->sole()->context;
        $otherConnection = MetaConnection::create(['page_id' => 'OTHER_PAGE', 'page_name' => 'Інший магазин', 'page_access_token' => 'test', 'status' => 'active']);
        foreach ([['from_id' => 'OTHER_CUSTOMER'], ['meta_connection_id' => $otherConnection->id], ['channel' => 'instagram']] as $scope) {
            $other = $this->comment($scope);
            $conversation = $this->conversation($other);
            $conversation->messages()->create(['direction' => 'out', 'sender' => 'ai', 'context' => $raw, 'text' => 'Копія', 'sent_at' => now()]);
            $this->assertNull($store->source($conversation));
        }
        $foreignTrigger = InboxMessage::where('inbox_conversation_id', '!=', $original->id)->first();
        $this->assertNull($store->source($original, $foreignTrigger));
    }

    public function test_legacy_mid_from_another_author_is_not_a_source_for_this_chat(): void
    {
        $comment = $this->comment(['status' => 'dm_sent', 'dm_message_id' => 'wrong-author-mid']);
        $other = $this->comment(['from_id' => 'OTHER_CUSTOMER']);
        $conversation = $this->conversation($other);
        $conversation->messages()->create(['direction' => 'out', 'sender' => 'agent', 'external_message_id' => $comment->dm_message_id,
            'text' => 'Не цей автор', 'sent_at' => now()]);
        $this->assertNull(app(CommentContextStore::class)->source($conversation));
    }

    public function test_success_without_remote_message_id_is_idempotent_by_comment_snapshot(): void
    {
        $comment = $this->comment();
        $this->capture($comment);
        $store = app(CommentContextStore::class);
        $conversation = $store->sent($comment, null);
        $store->sent($comment, null);
        $this->assertSame(1, $conversation->messages()->count());
        $this->assertSame($comment->id, $store->source($conversation)['comment_id']);
    }

    public function test_invalid_or_unavailable_catalog_ids_do_not_create_confident_product_context(): void
    {
        $comment = $this->comment();
        $this->capture($comment, [], ['product_ids' => [999999]]);
        $this->assertSame([], $comment->fresh()->gpt_context['products']);
        $this->assertSame([], $comment->fresh()->gpt_context['decision']['product_ids']);
        $this->assertSame('uncertain', $comment->fresh()->gpt_context['decision']['confidence']);
    }

    public function test_legacy_context_can_use_valid_existing_gpt_post_cache_without_any_network_or_database_write(): void
    {
        $comment = $this->comment(['status' => 'dm_sent', 'dm_message_id' => 'cached-legacy-mid', 'post_excerpt' => null, 'post_image' => null]);
        $conversation = $this->conversation($comment);
        $conversation->messages()->create(['direction' => 'out', 'sender' => 'agent', 'external_message_id' => 'cached-legacy-mid',
            'text' => 'Стара відповідь', 'sent_at' => now()]);
        $key = 'gpt-comment-context:v1:'.$comment->meta_connection_id.':'.$comment->channel.':'.hash('sha256', $comment->post_id);
        Cache::put($key, ['text' => 'Повний підпис із перевіреного кешу', 'locals' => ['../.env', 'inbox-context/product.png'],
            'post_url' => 'https://www.facebook.com/reel/123?access_token=secret#fragment'], now()->addHours(6));

        $source = app(CommentContextStore::class)->source($conversation);
        $this->assertSame('Повний підпис із перевіреного кешу', $source['post_text']);
        $this->assertSame(['inbox-context/product.png'], $source['image_paths']);
        $this->assertSame('https://www.facebook.com/reel/123', $source['post_url']);
        $this->assertSame([], $source['products']);
        $this->assertNull($comment->fresh()->gpt_context);
        $this->assertNull($comment->fresh()->post_excerpt);
        $this->assertNull($comment->fresh()->post_image);
    }

    public function test_canonical_post_url_keeps_only_public_numeric_identifiers_and_rejects_meta_redirect_routes(): void
    {
        $this->assertSame('https://www.facebook.com/permalink.php?id=42&story_fbid=123', CommentPostContext::safePostUrl(
            'https://www.facebook.com/permalink.php?access_token=secret&story_fbid=123&id=42&fbclid=tracking#fragment'));
        $this->assertSame('https://www.instagram.com/p/ABC/', CommentPostContext::safePostUrl('https://www.instagram.com/p/ABC/?igsh=secret#token'));
        foreach (['/l.php', '/flx/warn', '/share', '/sharer.php', '/dialog/oauth', '/login.php'] as $path) {
            $this->assertNull(CommentPostContext::safePostUrl('https://www.facebook.com'.$path.'?id=42'));
        }
        $this->assertNull(CommentPostContext::safePostUrl('https://user:secret@www.facebook.com/reel/1'));
        $this->assertNull(CommentPostContext::safePostUrl('https://www.facebook.com:443/reel/1'));
    }

    public function test_completed_old_quote_is_not_pinned_when_a_later_post_dm_arrives_without_new_inbound(): void
    {
        $first = $this->comment();
        $this->capture($first);
        $store = app(CommentContextStore::class);
        $conversation = $store->sent($first, 'quoted-post-a');
        $quoted = $conversation->messages()->create(['direction' => 'in', 'sender' => 'contact', 'text' => 'Ці',
            'context' => ['type' => 'reply', 'mid' => 'quoted-post-a'], 'sent_at' => now()]);
        AiRun::create(['inbox_conversation_id' => $conversation->id, 'inbox_message_id' => $quoted->id, 'status' => 'replied']);
        $second = $this->comment(['post_id' => 'POST_B']);
        $this->capture($second);
        $store->sent($second, 'later-post-b');
        $this->assertSame('POST_B', $store->source($conversation)['post_id']);
    }

    public function test_successful_meta_receipt_survives_context_store_failure_and_prevents_a_second_dm(): void
    {
        AiChannelSetting::current()->update(['default_provider' => 'gpt', 'gpt_enabled' => true, 'claude_enabled' => false]);
        AiSetting::global()->update(['comment_settings' => ['enabled' => true, 'facebook' => true]]);
        $comment = $this->comment(['text' => 'Хочу повернення, товар бракований']);
        $store = \Mockery::mock(CommentContextStore::class, [app(OutgoingMessageWriter::class)])->makePartial();
        $store->shouldReceive('sent')->once()->andThrow(new \RuntimeException('Тимчасова помилка збереження чату'));
        $store->shouldNotReceive('failed');
        $store->shouldNotReceive('skipped');
        $this->app->instance(CommentContextStore::class, $store);
        $send = \Mockery::mock(MetaSendService::class);
        $send->shouldReceive('sendPrivateReply')->once()->andReturn(['ok' => true, 'message_id' => 'durable-meta-receipt']);

        app(CommentResponder::class)->handle($comment->id, $send);
        app(CommentResponder::class)->handle($comment->id, $send);

        $comment->refresh();
        $this->assertSame('dm_sent', $comment->status);
        $this->assertSame('durable-meta-receipt', $comment->dm_message_id);
        $this->assertSame('sent', $comment->gpt_context['delivery_status']);
        $this->assertNull($comment->gpt_context['error']);
        $this->assertNotNull($comment->inbox_conversation_id);
        $this->assertSame('needs_human', $comment->conversation->chatStatus->code);
        $this->assertFalse($comment->conversation->ai_enabled);
    }

    public function test_echo_can_recover_sent_raw_context_from_durable_receipt_after_local_persistence_failed(): void
    {
        $comment = $this->comment();
        $conversation = $this->conversation($comment);
        $this->capture($comment);
        $raw = $comment->fresh()->gpt_context;
        $raw['delivery_status'] = 'sent';
        $comment->update(['status' => 'dm_sent', 'dm_message_id' => 'repaired-by-echo', 'gpt_context' => $raw]);
        $echo = $conversation->messages()->create(['direction' => 'out', 'sender' => 'agent', 'external_message_id' => 'repaired-by-echo',
            'text' => $raw['dm_text'], 'sent_at' => now()]);

        $source = app(CommentContextStore::class)->source($conversation);
        $this->assertSame('sent', $source['delivery_status']);
        $this->assertSame($echo->id, $source['_message_id']);
        $this->assertSame($comment->id, $source['comment_id']);
        $this->assertSame('380.00', $source['products'][0]['quoted_price']);
    }

    public function test_post_context_returns_verified_local_paths_for_durable_capture_and_cache_hits(): void
    {
        $comment = $this->comment();
        $context = app(CommentPostContext::class)->load($comment);
        $this->assertSame(['inbox-context/product.png'], $context['image_paths']);
        $this->assertCount(1, $context['images']);
        $this->assertStringStartsWith('data:image/png;base64,', $context['images'][0]);
        $this->assertNull($context['post_url']);
        $this->assertSame($context, app(CommentPostContext::class)->load($comment));
        app(CommentContextStore::class)->capture($comment, $context, ['intent' => 'other', 'product_ids' => [],
            'confidence' => 'uncertain', 'has_color' => false, 'has_size' => false], [], ['text' => 'Уточнення']);
        $this->assertSame($context['image_paths'], $comment->fresh()->gpt_context['image_paths']);
        $this->assertArrayNotHasKey('images', $comment->fresh()->gpt_context);
        $this->assertStringNotContainsString('base64', json_encode($comment->fresh()->gpt_context));
    }

    public function test_deleting_conversation_nulls_comment_link_and_preserves_encrypted_snapshot(): void
    {
        $comment = $this->comment();
        $this->capture($comment);
        $conversation = app(CommentContextStore::class)->sent($comment, 'conversation-to-delete');
        $raw = $comment->fresh()->gpt_context;
        $conversation->delete();
        $this->assertNotNull($comment->fresh());
        $this->assertNull($comment->fresh()->inbox_conversation_id);
        $this->assertSame($raw, $comment->fresh()->gpt_context);
        $this->assertSame('dm_sent', $comment->fresh()->status);
    }
}
