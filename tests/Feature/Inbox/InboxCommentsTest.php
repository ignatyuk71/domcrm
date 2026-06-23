<?php

namespace Tests\Feature\Inbox;

use App\Models\InboxComment;
use App\Models\MetaConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InboxCommentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config(['services.meta.app_secret' => '']);
    }

    private function connection(): MetaConnection
    {
        return MetaConnection::create([
            'page_id' => 'PAGE1',
            'page_name' => 'Test Page',
            'page_access_token' => 'token',
            'ig_account_id' => 'IG1',
            'status' => 'active',
        ]);
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => User::ROLE_OWNER, 'is_active' => true]);
    }

    private function fbCommentPayload(string $commentId, string $fromId, string $text): array
    {
        return [
            'object' => 'page',
            'entry' => [[
                'id' => 'PAGE1',
                'time' => 1700000000,
                'changes' => [[
                    'field' => 'feed',
                    'value' => [
                        'item' => 'comment',
                        'verb' => 'add',
                        'comment_id' => $commentId,
                        'post_id' => 'PAGE1_post42',
                        'parent_id' => 'PAGE1_post42',
                        'created_time' => 1700000000,
                        'message' => $text,
                        'from' => ['id' => $fromId, 'name' => 'Олена Тест'],
                    ],
                ]],
            ]],
        ];
    }

    public function test_fb_comment_is_stored_from_webhook(): void
    {
        $this->connection();

        $this->postMetaWebhook($this->fbCommentPayload('c_1', 'U777', 'Ціна?'))->assertOk();

        $this->assertDatabaseHas('inbox_comments', [
            'comment_id' => 'c_1',
            'channel' => 'facebook',
            'post_id' => 'PAGE1_post42',
            'from_name' => 'Олена Тест',
            'text' => 'Ціна?',
            'status' => 'new',
        ]);

        // Дубль не множиться
        $this->postMetaWebhook($this->fbCommentPayload('c_1', 'U777', 'Ціна?'))->assertOk();
        $this->assertSame(1, InboxComment::count());
    }

    public function test_own_page_comment_is_skipped(): void
    {
        $this->connection();

        $this->postMetaWebhook($this->fbCommentPayload('c_own', 'PAGE1', 'Дякуємо!'))->assertOk();

        $this->assertSame(0, InboxComment::count());
    }

    public function test_ig_comment_is_stored(): void
    {
        $this->connection();

        $payload = [
            'object' => 'instagram',
            'entry' => [[
                'id' => 'IG1',
                'changes' => [[
                    'field' => 'comments',
                    'value' => [
                        'id' => 'igc_1',
                        'text' => 'Хочу такі 😍',
                        'media' => ['id' => 'media_9'],
                        'from' => ['id' => 'U888', 'username' => 'olia_k'],
                    ],
                ]],
            ]],
        ];

        $this->postMetaWebhook($payload)->assertOk();

        $this->assertDatabaseHas('inbox_comments', [
            'comment_id' => 'igc_1',
            'channel' => 'instagram',
            'post_id' => 'media_9',
            'from_name' => 'olia_k',
        ]);
    }

    public function test_comments_endpoint_lists_and_counts(): void
    {
        $conn = $this->connection();
        InboxComment::create([
            'meta_connection_id' => $conn->id, 'channel' => 'facebook', 'post_id' => 'p1',
            'comment_id' => 'c_list', 'from_id' => 'U1', 'from_name' => 'Іра', 'text' => 'є 38?',
            'commented_at' => now(),
        ]);

        $res = $this->actingAs($this->owner())->getJson('/api/inbox/comments')->assertOk()->json();
        $this->assertSame(1, $res['new_count']);
        $this->assertSame('Іра', $res['items'][0]['from_name']);
        $this->assertSame('є 38?', $res['items'][0]['text']);
    }

    public function test_comment_can_be_deleted_via_endpoint(): void
    {
        $conn = $this->connection();
        $comment = InboxComment::create([
            'meta_connection_id' => $conn->id, 'channel' => 'instagram', 'post_id' => 'p_del',
            'comment_id' => 'c_del_1', 'from_id' => 'U1', 'text' => 'тест', 'commented_at' => now(),
        ]);

        $this->actingAs($this->owner())->deleteJson("/api/inbox/comments/{$comment->id}")
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('inbox_comments', ['id' => $comment->id]);
    }

    public function test_fb_comment_remove_webhook_deletes_stored_comment(): void
    {
        $conn = $this->connection();
        InboxComment::create([
            'meta_connection_id' => $conn->id, 'channel' => 'facebook', 'post_id' => 'PAGE1_post42',
            'comment_id' => 'c_rm_1', 'from_id' => 'U777', 'text' => 'видали мене', 'commented_at' => now(),
        ]);

        $payload = $this->fbCommentPayload('c_rm_1', 'U777', '');
        $payload['entry'][0]['changes'][0]['value']['verb'] = 'remove';

        $this->postMetaWebhook($payload)->assertOk();

        $this->assertDatabaseMissing('inbox_comments', ['comment_id' => 'c_rm_1']);
    }

    public function test_private_reply_sends_dm_and_marks_comment(): void
    {
        $conn = $this->connection();
        $comment = InboxComment::create([
            'meta_connection_id' => $conn->id, 'channel' => 'facebook', 'post_id' => 'p1',
            'comment_id' => 'c_dm', 'from_id' => 'U1', 'from_name' => 'Іра', 'text' => 'Ціна?',
            'commented_at' => now(),
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'm_pr_1'], 200)]);

        $this->actingAs($this->owner())
            ->postJson("/api/inbox/comments/{$comment->id}/dm", ['text' => 'Доброго дня! 530 грн 🙂'])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertSame('dm_sent', $comment->fresh()->status);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/PAGE1/messages')
                && ($request['recipient']['comment_id'] ?? null) === 'c_dm'
                && ($request['message']['text'] ?? null) === 'Доброго дня! 530 грн 🙂';
        });

        // Друга спроба — заборонена (Meta дозволяє одну приватну відповідь)
        $this->actingAs($this->owner())
            ->postJson("/api/inbox/comments/{$comment->id}/dm", ['text' => 'ще раз'])
            ->assertStatus(422);
    }
}
