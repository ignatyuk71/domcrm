<?php

namespace Tests\Feature\Inbox;

use App\Jobs\AiReplyToComment;
use App\Jobs\AiRespondToMessage;
use App\Models\AiPhotoGroup;
use App\Models\AiSetting;
use App\Models\InboxComment;
use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AiAgentService;
use App\Services\Meta\MetaSendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiAutomationTest extends TestCase
{
    use RefreshDatabase;

    private MetaConnection $conn;
    private InboxConversation $conv;

    private function setUpConversation(): InboxMessage
    {
        $this->conn = MetaConnection::create(['page_id' => 'P_AUT', 'page_name' => 'Shop', 'page_access_token' => 'tok', 'ig_account_id' => 'IG_AUT', 'status' => 'active']);
        AiSetting::global()->update(['api_key' => 'sk-ant-test', 'model' => 'claude-sonnet-4-6']);
        AiSetting::forConnection($this->conn->id)->update(['enabled' => true, 'system_prompt' => 'Ти продавець.']);
        $contact = InboxContact::create(['meta_connection_id' => $this->conn->id, 'channel' => 'facebook', 'external_id' => 'U_AUT']);
        $this->conv = InboxConversation::create(['meta_connection_id' => $this->conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook']);

        return InboxMessage::create([
            'inbox_conversation_id' => $this->conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_aut_' . uniqid(), 'text' => 'Ціна?', 'sent_at' => now(),
        ]);
    }

    // --- Графік ---

    public function test_schedule_window_logic(): void
    {
        $w = ['mode' => 'window', 'from' => '20:00', 'to' => '09:00'];
        $this->assertTrue(AiAgentService::scheduleAllows($w, now()->setTime(21, 0)));
        $this->assertTrue(AiAgentService::scheduleAllows($w, now()->setTime(3, 30)));
        $this->assertFalse(AiAgentService::scheduleAllows($w, now()->setTime(14, 0)));
        $this->assertTrue(AiAgentService::scheduleAllows(null, now()->setTime(14, 0)));
        $this->assertTrue(AiAgentService::scheduleAllows(['mode' => 'always'], now()->setTime(14, 0)));
        $day = ['mode' => 'window', 'from' => '09:00', 'to' => '18:00'];
        $this->assertTrue(AiAgentService::scheduleAllows($day, now()->setTime(12, 0)));
        $this->assertFalse(AiAgentService::scheduleAllows($day, now()->setTime(20, 0)));
    }

    public function test_outside_schedule_skips_and_sweep_retries_later(): void
    {
        $msg = $this->setUpConversation();
        // Вікно, що точно НЕ містить поточний час
        $from = now()->addHours(2)->format('H:i');
        $to = now()->addHours(3)->format('H:i');
        AiSetting::forConnection($this->conn->id)->update(['schedule' => ['mode' => 'window', 'from' => $from, 'to' => $to]]);

        Http::fake();
        (new AiRespondToMessage($this->conv->id, $msg->id))->handle(app(AiAgentService::class));

        $this->assertDatabaseHas('ai_runs', ['inbox_message_id' => $msg->id, 'status' => 'skipped_schedule']);
        Http::assertNothingSent();

        // Вікно «відкрилось» → добирач НЕ вважає skipped_schedule обробленим
        AiSetting::forConnection($this->conn->id)->update(['schedule' => ['mode' => 'always']]);
        $msg->created_at = now()->subMinutes(5);
        $msg->save();

        Http::swap(new \Illuminate\Http\Client\Factory()); // скинути попередній catch-all fake
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Вітаю! 🙂']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 100, 'output_tokens' => 10],
            ], 200),
            'graph.facebook.com/*' => Http::response(['message_id' => 'm_sched_out'], 200),
        ]);

        $this->artisan('ai:sweep')->assertSuccessful();
        $this->assertDatabaseHas('ai_runs', ['inbox_message_id' => $msg->id, 'status' => 'replied']);
    }

    // --- Липка пауза ---

    public function test_operator_message_pauses_ai_and_toggle_clears(): void
    {
        $msg = $this->setUpConversation();
        $owner = User::factory()->create(['role' => User::ROLE_OWNER, 'is_active' => true]);

        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'm_op_1'], 200)]);
        $this->actingAs($owner)->postJson("/api/inbox/conversations/{$this->conv->id}/send", ['text' => 'я сам відповім'])
            ->assertOk();

        $this->conv->refresh();
        $this->assertNotNull($this->conv->ai_paused_until);
        // Пауза тепер коротка (хвилини, дефолт 3), а не години.
        $this->assertTrue($this->conv->ai_paused_until->gt(now()));
        $this->assertTrue($this->conv->ai_paused_until->lte(now()->addMinutes(5)));

        // ШІ мовчить на наступне вхідне
        $next = InboxMessage::create([
            'inbox_conversation_id' => $this->conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_after_op', 'text' => 'а коли?', 'sent_at' => now(),
        ]);
        Http::fake();
        (new AiRespondToMessage($this->conv->id, $next->id))->handle(app(AiAgentService::class));
        $this->assertDatabaseHas('ai_runs', ['inbox_message_id' => $next->id, 'status' => 'skipped_operator_pause']);

        // Тумблер ON знімає паузу
        $this->actingAs($owner)->postJson("/api/inbox/conversations/{$this->conv->id}/ai", ['enabled' => true])->assertOk();
        $this->assertNull($this->conv->fresh()->ai_paused_until);
    }

    public function test_page_echo_pauses_but_our_private_reply_echo_does_not(): void
    {
        $this->setUpConversation();
        config(['services.meta.app_secret' => '']);

        $comment = InboxComment::create([
            'meta_connection_id' => $this->conn->id, 'channel' => 'facebook', 'post_id' => 'p1',
            'comment_id' => 'c_pr', 'from_id' => 'U_AUT', 'text' => 'Ціна?', 'status' => 'dm_sent',
            'dm_message_id' => 'mid_private_reply_1', 'commented_at' => now(),
        ]);

        // Ехо НАШОЇ приватної відповіді → sender ai, БЕЗ паузи
        $echo = [
            'object' => 'page',
            'entry' => [[
                'id' => 'P_AUT',
                'messaging' => [[
                    'sender' => ['id' => 'P_AUT'],
                    'recipient' => ['id' => 'U_AUT'],
                    'timestamp' => 1700000000000,
                    'message' => ['mid' => 'mid_private_reply_1', 'text' => 'Доброго дня!', 'is_echo' => true],
                ]],
            ]],
        ];
        $this->postJson('/api/meta/webhook', $echo)->assertOk();

        $m = InboxMessage::where('external_message_id', 'mid_private_reply_1')->first();
        $this->assertSame('ai', $m->sender);
        $this->assertNull($m->conversation->ai_paused_until);

        // Ехо живого оператора (інший mid) → sender agent + пауза
        $echo['entry'][0]['messaging'][0]['message'] = ['mid' => 'mid_operator_1', 'text' => 'відповім сам', 'is_echo' => true];
        $this->postJson('/api/meta/webhook', $echo)->assertOk();

        $m2 = InboxMessage::where('external_message_id', 'mid_operator_1')->first();
        $this->assertSame('agent', $m2->sender);
        $this->assertNotNull($m2->conversation->fresh()->ai_paused_until);
    }

    // --- Автоворонка коментарів ---

    private function makeComment(string $text, ?string $postExcerpt, string $channel = 'facebook'): InboxComment
    {
        return InboxComment::create([
            'meta_connection_id' => $this->conn->id, 'channel' => $channel, 'post_id' => 'post_x',
            'post_excerpt' => $postExcerpt, 'comment_id' => 'c_' . uniqid(), 'from_id' => 'U9',
            'from_name' => 'Klientka', 'text' => $text, 'commented_at' => now(),
        ]);
    }

    public function test_comment_funnel_ai_classifies_post_caches_per_post_and_replies(): void
    {
        $this->setUpConversation();
        $g = AiPhotoGroup::create(['name' => 'Вуличні пухнасті тапки']);
        $g->products()->attach(Product::create(['title' => 'Вуличні чорні', 'sku' => 'V9', 'sale_price' => 530, 'currency' => 'UAH', 'is_active' => true])->id);

        AiSetting::global()->update(['comment_settings' => ['enabled' => true, 'facebook' => true, 'instagram' => true, 'opener' => 'Відкривач']]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Вуличні пухнасті тапки']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 300, 'output_tokens' => 5],
            ], 200),
            'graph.facebook.com/*' => Http::response(['message_id' => 'mid_funnel_1'], 200),
        ]);

        $comment = $this->makeComment('Ціна?', 'Затишок для ваших ніжок надворі цієї зими 🤍');
        (new AiReplyToComment($comment->id))->handle(app(MetaSendService::class));

        $comment->refresh();
        $this->assertSame('dm_sent', $comment->status);
        $this->assertSame('mid_funnel_1', $comment->dm_message_id);
        $this->assertSame('Вуличні пухнасті тапки', $comment->matched_group_name);
        // Класифікацію закешовано на пост
        $this->assertDatabaseHas('ai_post_lines', ['post_id' => 'post_x', 'ai_photo_group_id' => $g->id, 'source' => 'ai']);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/P_AUT/messages')
            && str_contains((string) ($r['message']['text'] ?? ''), '530 грн'));

        // Другий коментар того ж поста → з кешу, БЕЗ нового виклику Claude
        $anthropicCalls = collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'api.anthropic.com'))->count();
        $comment2 = $this->makeComment('І мені ціну', 'Затишок для ваших ніжок надворі цієї зими 🤍');
        (new AiReplyToComment($comment2->id))->handle(app(MetaSendService::class));
        $this->assertSame('dm_sent', $comment2->fresh()->status);
        $anthropicCallsAfter = collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'api.anthropic.com'))->count();
        $this->assertSame($anthropicCalls, $anthropicCallsAfter);
    }

    public function test_comment_funnel_ignores_comments_created_before_enabling(): void
    {
        $this->setUpConversation();
        AiSetting::global()->update(['comment_settings' => [
            'enabled' => true, 'facebook' => true, 'instagram' => true,
            'opener' => 'X', 'enabled_at' => now()->toDateTimeString(),
        ]]);

        // Коментар, що існував ДО ввімкнення рубильника
        $old = $this->makeComment('Ціна?', 'будь-що');
        $old->created_at = now()->subHours(2);
        $old->save();

        Http::fake();
        (new AiReplyToComment($old->id))->handle(app(MetaSendService::class));

        $this->assertSame('new', $old->fresh()->status); // лишився для ручної відповіді
        Http::assertNothingSent();
    }

    public function test_comment_funnel_replies_to_every_comment_and_respects_switches(): void
    {
        $this->setUpConversation();
        AiSetting::global()->update(['comment_settings' => ['enabled' => true, 'facebook' => true, 'instagram' => false, 'opener' => 'Підкажіть, які тапулі цікавлять?']]);

        // 1) ШІ не розпізнав («невідомо») → відкривач
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'невідомо']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 200, 'output_tokens' => 3],
            ], 200),
            'graph.facebook.com/*' => Http::response(['message_id' => 'mid_open_1'], 200),
        ]);
        $c1 = $this->makeComment('Ціна?', 'Просто красиве відео без назви');
        (new AiReplyToComment($c1->id))->handle(app(MetaSendService::class));
        $this->assertSame('dm_sent', $c1->fresh()->status);
        Http::assertSent(fn ($r) => str_contains((string) ($r['message']['text'] ?? ''), 'Підкажіть, які тапулі цікавлять?'));
        $this->assertDatabaseHas('ai_post_lines', ['post_id' => 'post_x', 'ai_photo_group_id' => null, 'source' => 'none']);

        // 2) БУДЬ-ЯКИЙ коментар (без слова «ціна») того ж поста → теж відповідаємо (з кешу)
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'mid_open_2'], 200)]);
        $c2 = $this->makeComment('Класні! 😍', 'будь-що');
        (new AiReplyToComment($c2->id))->handle(app(MetaSendService::class));
        $this->assertSame('dm_sent', $c2->fresh()->status);

        // 3) Instagram вимкнено → мовчимо
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake();
        $c3 = $this->makeComment('Ціна?', 'будь-що', 'instagram');
        (new AiReplyToComment($c3->id))->handle(app(MetaSendService::class));
        $this->assertSame('new', $c3->fresh()->status);
        Http::assertNothingSent();

        // 4) Головний рубильник вимкнено → мовчимо
        AiSetting::global()->update(['comment_settings' => ['enabled' => false]]);
        $c4 = $this->makeComment('Ціна?', 'вуличні');
        (new AiReplyToComment($c4->id))->handle(app(MetaSendService::class));
        $this->assertSame('new', $c4->fresh()->status);
    }

    public function test_comment_conversation_link_resolves_via_echo_mid(): void
    {
        $this->setUpConversation();
        $owner = User::factory()->create(['role' => User::ROLE_OWNER, 'is_active' => true]);

        $comment = InboxComment::create([
            'meta_connection_id' => $this->conn->id, 'channel' => 'facebook', 'post_id' => 'p1',
            'comment_id' => 'c_link', 'from_id' => 'U_AUT', 'text' => 'Ціна?', 'status' => 'dm_sent',
            'dm_message_id' => 'mid_link_1', 'commented_at' => now(),
        ]);

        // Поки еха нема — null
        $this->actingAs($owner)->getJson("/api/inbox/comments/{$comment->id}/conversation")
            ->assertOk()->assertJson(['conversation_id' => null]);

        // Ехо доїхало → повідомлення в розмові → лінк працює
        InboxMessage::create([
            'inbox_conversation_id' => $this->conv->id, 'direction' => 'out', 'sender' => 'ai',
            'external_message_id' => 'mid_link_1', 'text' => 'Доброго дня!', 'sent_at' => now(),
        ]);
        $this->actingAs($owner)->getJson("/api/inbox/comments/{$comment->id}/conversation")
            ->assertOk()->assertJson(['conversation_id' => $this->conv->id]);
    }
}

/** Хелпер: дістати comment_id з виклику (для читабельності перевірки). */
function request_comment_id($request): ?string
{
    return $request['recipient']['comment_id'] ?? null;
}
