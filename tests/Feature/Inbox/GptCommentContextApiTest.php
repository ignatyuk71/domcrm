<?php

namespace Tests\Feature\Inbox;

use App\Models\{InboxComment, InboxContact, InboxConversation, InboxMessage, MetaConnection, Product, User};
use App\Services\Ai\Gpt\{CommentContextPresenter, CommentContextStore};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Http};
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GptCommentContextApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'is_active' => true]));
    }

    public function test_comments_and_chat_show_the_same_public_card_without_echo_or_network_calls(): void
    {
        [$comment, $conversation, $message, $product] = $this->source();
        $product->update(['sale_price' => '450.00']);

        $comments = $this->getJson('/api/inbox/comments')->assertOk()->json('items');
        $card = $comments[0]['source_context'];
        $this->assertSame($conversation->id, $comments[0]['conversation_id']);
        $this->assertSame($comment->text, $card['comment_text']);
        $this->assertSame('380 грн', $card['products'][0]['price']);
        $this->assertSame('sent', $card['status']);
        $this->assertSame('Магазин тест', $card['store']);
        $this->assertSame('https://www.instagram.com/p/product/', $card['post_url']);
        foreach (['gpt_context', 'from_id', 'meta_connection_id', 'image_paths', 'decision'] as $private) $this->assertArrayNotHasKey($private, $card);
        $this->assertArrayNotHasKey('gpt_context', $comment->toArray());
        $this->getJson("/api/inbox/comments/{$comment->id}/conversation")->assertOk()->assertJson(['conversation_id' => $conversation->id]);

        $response = $this->getJson("/api/inbox/conversations/{$conversation->id}/messages")->assertOk()->json();
        $this->assertSame($card['comment_text'], $response['conversation']['source_context']['comment_text']);
        $this->assertSame($message->id, $response['messages'][0]['id']);
        $this->assertSame('gpt_comment', $response['messages'][0]['context']['type']);
        $this->assertStringNotContainsString('secret-token', json_encode($response));
        Http::assertNothingSent();
    }

    public function test_legacy_dm_link_is_scoped_and_preview_is_read_only(): void
    {
        [$comment, $conversation, $message] = $this->source();
        $comment->update(['gpt_context' => null, 'inbox_conversation_id' => null, 'post_excerpt' => 'Старий допис']);
        $message->update(['context' => null]);
        Cache::put('gpt-comment-context:v1:'.$comment->meta_connection_id.':'.$comment->channel.':'.hash('sha256', $comment->post_id), ['text' => 'Збережений повний допис', 'locals' => []]);
        $original = $comment->getRawOriginal();

        $card = $this->getJson('/api/inbox/comments')->assertOk()->json('items.0.source_context');
        $this->assertSame($conversation->id, $card['conversation_id']);
        $this->assertSame('Збережений повний допис', $card['post_text']);
        $this->assertSame([], $card['products']);
        $this->assertSame($original, $comment->fresh()->getRawOriginal());

        // Навіть точний mid не відкриває чужу розмову за помилкового звʼязку.
        $conversation->contact->update(['external_id' => 'another-person']);
        $this->getJson("/api/inbox/comments/{$comment->id}/conversation")->assertOk()->assertJson(['conversation_id' => null]);
        Http::assertNothingSent();
    }

    public function test_reset_hides_active_source_but_keeps_historical_operator_card(): void
    {
        [, $conversation, $message] = $this->source();
        $conversation->update(['ai_context_after_id' => $message->id]);

        $response = $this->getJson("/api/inbox/conversations/{$conversation->id}/messages")->assertOk()->json();

        $this->assertNull($response['conversation']['source_context']);
        $this->assertSame('gpt_comment', $response['messages'][0]['context']['type']);
        Http::assertNothingSent();
    }

    public function test_deleted_comment_keeps_the_scoped_snapshot_in_chat(): void
    {
        [$comment, $conversation] = $this->source();
        $text = $comment->text;
        $comment->delete();

        $response = $this->getJson("/api/inbox/conversations/{$conversation->id}/messages")->assertOk()->json();

        $this->assertSame($text, $response['messages'][0]['context']['comment_text']);
        $this->assertSame($text, $response['conversation']['source_context']['comment_text']);
        Http::assertNothingSent();
    }

    public function test_cross_store_snapshot_is_never_exposed_in_chat(): void
    {
        [, $conversation, $message] = $this->source();
        $context = $message->context;
        $context['meta_connection_id']++;
        $context['comment_text'] = 'Чужий приватний коментар';
        $message->update(['context' => $context]);

        $response = $this->getJson("/api/inbox/conversations/{$conversation->id}/messages")->assertOk()->json();

        $this->assertNull($response['conversation']['source_context']);
        $this->assertNull($response['messages'][0]['context']);
        $this->assertStringNotContainsString('Чужий приватний коментар', json_encode($response, JSON_UNESCAPED_UNICODE));
        Http::assertNothingSent();
    }

    #[DataProvider('unsafePostUrls')]
    public function test_public_card_rejects_unsafe_links_and_paths(string $url): void
    {
        [$comment, $conversation, $message] = $this->source();
        $context = $message->context;
        $context['post_url'] = $url;
        $context['image_paths'] = ['../.env', 'https://example.com/track.jpg', 'inbox-context/../.env'];
        $context['post_text'] = '<script>alert(1)</script>Товар';
        $message->context = $context;

        $card = app(CommentContextPresenter::class)->forMessages(collect([$message]), $conversation)[$message->id];

        $this->assertNull($card['post_url']);
        $this->assertNull($card['image']);
        $this->assertStringNotContainsString('<script>', $card['post_text']);
        Http::assertNothingSent();
    }

    public static function unsafePostUrls(): array
    {
        return array_map(fn ($url) => [$url], ['javascript:alert(1)', 'https://example.com/product',
            'https://www.instagram.com:8443/p/product/', 'https://user:pass@www.instagram.com/p/product/',
            'https://www.facebook.com/l.php?u=https://evil.test', 'https://www.facebook.com/share?u=https://evil.test']);
    }

    private function source(): array
    {
        $connection = MetaConnection::create(['page_id' => 'page-'.Str::uuid(), 'page_name' => 'Магазин тест', 'page_access_token' => 'secret-token', 'status' => 'active']);
        $comment = InboxComment::create(['meta_connection_id' => $connection->id, 'channel' => 'instagram',
            'post_id' => 'post-'.Str::uuid(), 'comment_id' => 'comment-'.Str::uuid(), 'from_id' => 'customer-'.Str::uuid(),
            'from_name' => 'Олена', 'text' => 'Беру чорні 38', 'commented_at' => now()]);
        $product = Product::create(['title' => 'Капці Teddy', 'sale_price' => '380.00', 'is_active' => true]);
        $store = app(CommentContextStore::class);
        $store->capture($comment, ['text' => 'Домашні капці Teddy', 'image_paths' => [],
            'post_url' => 'https://www.instagram.com/p/product/?access_token=secret-token'],
            ['intent' => 'order', 'product_ids' => [$product->id], 'confidence' => 'clear', 'has_color' => true, 'has_size' => true],
            [['id' => $product->id, 'title' => $product->title, 'color' => 'чорний', 'price' => '380.00', 'available' => true]],
            ['text' => 'Ціна 380 грн. Уточніть кількість.', 'handoff' => false]);
        $conversation = $store->sent($comment, 'mid-'.Str::uuid());
        $message = $conversation->messages()->firstOrFail();

        return [$comment->fresh(), $conversation, $message, $product];
    }
}
