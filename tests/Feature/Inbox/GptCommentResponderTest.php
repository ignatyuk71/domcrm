<?php

namespace Tests\Feature\Inbox;

use App\Jobs\AiReplyToComment;
use App\Models\{AiChannelSetting, AiPhotoGroup, AiPostLine, AiSetting, InboxComment, InboxContact, InboxConversation, MetaConnection, Product};
use App\Services\Ai\Gpt\{CommentCatalog, CommentDecision, CommentPostContext};
use App\Services\Meta\MetaSendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Http};
use Tests\TestCase;

class GptCommentResponderTest extends TestCase
{
    use RefreshDatabase;

    private MetaConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        $this->connection = MetaConnection::create(['page_id' => 'COMMENT_PAGE', 'page_name' => 'Магазин',
            'page_access_token' => 'test-token', 'status' => 'active']);
        AiChannelSetting::current()->update(['default_provider' => 'gpt', 'gpt_enabled' => true,
            'claude_enabled' => false, 'gpt_api_key' => 'sk-test-comments', 'gpt_model' => 'gpt-test']);
        AiSetting::global()->update(['comment_settings' => ['enabled' => true, 'facebook' => true, 'instagram' => true]]);
    }

    private function comment(string $text = 'Ціна?', array $extra = []): InboxComment
    {
        return InboxComment::create($extra + ['meta_connection_id' => $this->connection->id, 'channel' => 'facebook',
            'post_id' => 'COMMENT_POST', 'post_excerpt' => 'Твої домашні ведмедики, рожеві та коричневі. Ціна 380 грн.',
            'comment_id' => 'comment-'.uniqid(), 'from_id' => 'COMMENT_USER', 'from_name' => 'Олена',
            'text' => $text, 'status' => 'new', 'commented_at' => now()]);
    }

    private function product(string $title = 'Teddy Bear Рожевий', string $price = '380.00'): Product
    {
        $product = Product::create(['title' => $title, 'sale_price' => $price, 'currency' => 'UAH',
            'is_active' => true, 'stock_qty' => 5]);
        $group = AiPhotoGroup::firstOrCreate(['name' => 'Жіночі теплі домашні капці']);
        $group->products()->attach($product);

        return $product;
    }

    private function decision(array $extra = []): array
    {
        return $extra + ['intent' => 'price', 'product_ids' => [], 'confidence' => 'clear', 'has_color' => false, 'has_size' => false];
    }

    private function fakeAnalysis(array|callable $result): void
    {
        $context = \Mockery::mock(CommentPostContext::class);
        $context->shouldReceive('load')->andReturn(['text' => 'Твої домашні ведмедики', 'images' => [], 'fingerprint' => 'test']);
        $this->app->instance(CommentPostContext::class, $context);
        $decision = \Mockery::mock(CommentDecision::class)->makePartial();
        $expectation = $decision->shouldReceive('decide');
        if (is_callable($result)) $expectation->andReturnUsing($result);
        else $expectation->andReturn($result);
        $this->app->instance(CommentDecision::class, $decision);
    }

    private function processComment(InboxComment $comment): void
    {
        (new AiReplyToComment($comment->id))->handle(app(MetaSendService::class));
    }

    public function test_exact_catalog_product_overrides_wrong_legacy_post_group_without_changing_it(): void
    {
        $bear = $this->product();
        $fur = AiPhotoGroup::create(['name' => 'Домашні пухнасті тапочки з хутра']);
        $fur->products()->attach(Product::create(['title' => 'Хутряні капці', 'sale_price' => '399.00', 'is_active' => true, 'stock_qty' => 2]));
        AiPostLine::create(['post_id' => 'COMMENT_POST', 'ai_photo_group_id' => $fur->id, 'source' => 'ai']);
        $this->fakeAnalysis($this->decision(['product_ids' => [$bear->id]]));
        $comment = $this->comment();
        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'bear-price'])]);

        $this->processComment($comment);

        $this->assertSame('dm_sent', $comment->fresh()->status);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/COMMENT_PAGE/messages')
            && str_contains($r['message']['text'], '380 грн') && ! str_contains($r['message']['text'], '399'));
        $this->assertDatabaseHas('ai_post_lines', ['post_id' => 'COMMENT_POST', 'ai_photo_group_id' => $fur->id]);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'anthropic'));
    }

    public function test_price_is_rechecked_after_analysis_and_before_delivery(): void
    {
        $product = $this->product();
        $this->fakeAnalysis(function () use ($product): array {
            $product->update(['sale_price' => '410.25']);
            return $this->decision(['product_ids' => [$product->id]]);
        });
        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'fresh-price'])]);

        $this->processComment($this->comment());

        Http::assertSent(fn ($r) => str_contains($r['message']['text'] ?? '', '410,25 грн')
            && ! str_contains($r['message']['text'], '380 грн'));
    }

    public function test_obvious_complaint_reaches_operator_without_model_or_context_and_keeps_order_draft(): void
    {
        $contact = InboxContact::create(['meta_connection_id' => $this->connection->id, 'channel' => 'facebook', 'external_id' => 'COMMENT_USER']);
        $draft = ['status' => 'draft', 'items' => [['product_id' => 1, 'qty' => 2]]];
        $conversation = InboxConversation::create(['meta_connection_id' => $this->connection->id, 'inbox_contact_id' => $contact->id,
            'channel' => 'facebook', 'ai_enabled' => true, 'ai_checkout_data' => $draft]);
        $context = \Mockery::mock(CommentPostContext::class); $context->shouldNotReceive('load');
        $this->app->instance(CommentPostContext::class, $context);
        $decision = \Mockery::mock(CommentDecision::class)->makePartial(); $decision->shouldNotReceive('decide');
        $this->app->instance(CommentDecision::class, $decision);
        $comment = $this->comment('Не дуже, в мене вони розтягнулись і стали дуже великі');
        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'complaint-ack'])]);

        $this->processComment($comment);
        $this->processComment($comment->fresh());

        $conversation->refresh();
        $this->assertFalse($conversation->ai_enabled);
        $this->assertSame('needs_human', $conversation->chatStatus->code);
        $this->assertSame($draft, $conversation->ai_checkout_data);
        $this->assertSame(1, $conversation->unread_count);
        $this->assertSame(1, $conversation->ai_provider_version);
        $this->assertSame(1, $conversation->messages()->where('direction', 'in')->count());
        $this->assertStringContainsString('розтягнулись', $conversation->messages()->where('direction', 'in')->first()->text);
        $this->assertSame(1, $conversation->messages()->where('external_message_id', 'complaint-ack')->count());
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'openai.com') || str_contains($r->url(), 'anthropic')
            || str_contains($r['message']['text'] ?? '', 'грн') || str_contains($r['message']['text'] ?? '', 'колір'));
    }

    public function test_complaint_is_handed_off_even_when_cooldown_prevents_another_dm(): void
    {
        $this->comment('Ціна?', ['status' => 'dm_sent', 'dm_message_id' => 'previous-dm']);
        $comment = $this->comment('Хочу повернення, товар бракований');
        Http::fake();

        $this->processComment($comment);

        $this->assertSame('dm_skipped', $comment->fresh()->status);
        $conversation = InboxConversation::firstOrFail();
        $this->assertSame('needs_human', $conversation->chatStatus->code);
        $this->assertFalse($conversation->ai_enabled);
        Http::assertNothingSent();
    }

    public function test_praise_does_not_trigger_a_sales_dm(): void
    {
        $this->fakeAnalysis($this->decision(['intent' => 'praise', 'confidence' => 'clear']));
        $comment = $this->comment('Тапулі супер, рекомендую!');
        Http::fake();

        $this->processComment($comment);

        $this->assertSame('dm_skipped', $comment->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_model_failure_asks_for_the_product_without_made_up_price(): void
    {
        $this->fakeAnalysis(function (): never { throw new \RuntimeException('Недоступна модель'); });
        $comment = $this->comment();
        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'clarification'])]);

        $this->processComment($comment);

        $this->assertSame('dm_sent', $comment->fresh()->status);
        $this->assertNull($comment->fresh()->matched_group_name);
        Http::assertSent(fn ($r) => ! str_contains($r['message']['text'] ?? '', 'грн'));
    }

    public function test_switching_provider_during_analysis_prevents_outbound_reply(): void
    {
        $this->fakeAnalysis(function (): array {
            AiChannelSetting::current()->update(['default_provider' => 'claude', 'claude_enabled' => true]);
            return $this->decision(['confidence' => 'uncertain']);
        });
        $comment = $this->comment();
        Http::fake();

        $this->processComment($comment);

        $this->assertSame('new', $comment->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_disabled_channel_and_comments_before_enabled_at_remain_untouched(): void
    {
        AiSetting::global()->update(['comment_settings' => ['enabled' => true, 'facebook' => false]]);
        Http::fake();
        $comment = $this->comment();
        $this->processComment($comment);
        $this->assertSame('new', $comment->fresh()->status);
        AiSetting::global()->update(['comment_settings' => ['enabled' => true, 'enabled_at' => now()->addMinute()->toIso8601String()]]);
        $this->processComment($comment);
        Http::assertNothingSent();
    }

    public function test_handoff_remains_visible_when_meta_cannot_deliver_acknowledgment(): void
    {
        $comment = $this->comment('Брак, прошу повернення');
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Недоступна відправка']], 400)]);

        $this->processComment($comment);

        $this->assertSame('dm_failed', $comment->fresh()->status);
        $this->assertSame('needs_human', InboxConversation::firstOrFail()->chatStatus->code);
        $this->assertFalse(InboxConversation::firstOrFail()->ai_enabled);
    }

    public function test_model_detected_complaint_also_reaches_operator_without_a_sales_reply(): void
    {
        $this->fakeAnalysis($this->decision(['intent' => 'complaint', 'confidence' => 'clear']));
        $comment = $this->comment('Маю питання щодо попередньої покупки');
        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'semantic-complaint'])]);

        $this->processComment($comment);

        $this->assertSame('dm_sent', $comment->fresh()->status);
        $this->assertSame('needs_human', InboxConversation::firstOrFail()->chatStatus->code);
        Http::assertSent(fn ($r) => ! str_contains($r['message']['text'] ?? '', 'грн'));
    }

    public function test_two_simultaneous_comments_from_one_contact_do_not_send_two_dms(): void
    {
        $product = $this->product();
        $this->fakeAnalysis($this->decision(['product_ids' => [$product->id]]));
        $first = $this->comment();
        $second = $this->comment('А на ці ціна?', ['post_id' => 'ANOTHER_POST']);
        Http::fake(function () use ($second) {
            $this->processComment($second);
            $this->assertSame('new', $second->fresh()->status);
            return Http::response(['message_id' => 'one-contact-dm']);
        });

        $this->processComment($first);
        $this->processComment($second->fresh());

        $this->assertSame('dm_sent', $first->fresh()->status);
        $this->assertSame('dm_skipped', $second->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_disabling_comments_during_analysis_prevents_the_reply(): void
    {
        $this->fakeAnalysis(function (): array {
            AiSetting::global()->update(['comment_settings' => ['enabled' => false]]);
            return $this->decision(['confidence' => 'uncertain']);
        });
        $comment = $this->comment();
        Http::fake();

        $this->processComment($comment);

        $this->assertSame('new', $comment->fresh()->status);
        Http::assertNothingSent();
    }
}
