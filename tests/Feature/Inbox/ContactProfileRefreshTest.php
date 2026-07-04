<?php

namespace Tests\Feature\Inbox;

use App\Models\InboxContact;
use App\Models\MetaConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContactProfileRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.meta.app_secret' => 'test-secret']);
    }

    private function postWebhook(array $payload)
    {
        $body = json_encode($payload);
        $sig = 'sha256=' . hash_hmac('sha256', $body, (string) config('services.meta.app_secret'));

        return $this->call('POST', '/api/meta/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $sig,
        ], $body);
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

    private function igPayload(string $mid): array
    {
        return [
            'object' => 'instagram',
            'entry' => [[
                'id' => 'IG1',
                'messaging' => [[
                    'sender' => ['id' => 'USER1'],
                    'recipient' => ['id' => 'IG1'],
                    'timestamp' => 1700000000000,
                    'message' => ['mid' => $mid, 'text' => 'Привіт'],
                ]],
            ]],
        ];
    }

    private array $createdFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $rel) {
            @unlink(public_path(strtok($rel, '?')));
        }
        parent::tearDown();
    }

    private function fakeProfile(?string $pic, bool $cdnAlive = true): void
    {
        Http::fake([
            'graph.facebook.com/*/USER1*' => $pic
                ? Http::response(['name' => 'Ірина', 'username' => 'iryna', 'profile_pic' => $pic])
                : Http::response(['error' => ['code' => 3, 'message' => 'capability']], 400),
            'graph.facebook.com/*' => Http::response([]),
            'cdn.meta/*' => $cdnAlive
                ? Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg'])
                : Http::response('gone', 404),
        ]);
    }

    private function assertLocalAvatar(InboxContact $contact): void
    {
        $this->assertStringStartsWith('inbox-avatars/c' . $contact->id, $contact->profile_pic);
        $this->createdFiles[] = $contact->profile_pic;
        $this->assertFileExists(public_path(strtok($contact->profile_pic, '?')));
    }

    public function test_new_contact_gets_avatar_and_checked_stamp(): void
    {
        $conn = $this->connection();
        $this->fakeProfile('https://cdn.meta/pic1.jpg');

        $this->postWebhook($this->igPayload('m_p1'))->assertOk();

        $contact = InboxContact::where('external_id', 'USER1')->firstOrFail();
        $this->assertLocalAvatar($contact); // аватарка одразу з нашого домену
        $this->assertNotNull($contact->profile_pic_checked_at);
    }

    public function test_dead_cdn_keeps_remote_url_as_fallback(): void
    {
        $conn = $this->connection();
        $this->fakeProfile('https://cdn.meta/pic1.jpg', cdnAlive: false);

        $this->postWebhook($this->igPayload('m_p5'))->assertOk();

        // Скачати не вдалося — лишаємо віддалений лінк, тижневий рефетч доспробує.
        $contact = InboxContact::where('external_id', 'USER1')->firstOrFail();
        $this->assertSame('https://cdn.meta/pic1.jpg', $contact->profile_pic);
    }

    public function test_stale_avatar_is_refreshed_weekly(): void
    {
        $conn = $this->connection();
        InboxContact::create([
            'meta_connection_id' => $conn->id,
            'channel' => 'instagram',
            'external_id' => 'USER1',
            'name' => 'Ірина',
            'profile_pic' => 'https://cdn.meta/old.jpg',
            'profile_pic_checked_at' => now()->subDays(8),
        ]);
        $this->fakeProfile('https://cdn.meta/fresh.jpg');

        $this->postWebhook($this->igPayload('m_p2'))->assertOk();

        $contact = InboxContact::where('external_id', 'USER1')->firstOrFail();
        $this->assertLocalAvatar($contact);
        $this->assertTrue($contact->profile_pic_checked_at->gt(now()->subMinute()));
    }

    public function test_recently_checked_contact_is_not_refetched(): void
    {
        $conn = $this->connection();
        InboxContact::create([
            'meta_connection_id' => $conn->id,
            'channel' => 'instagram',
            'external_id' => 'USER1',
            'name' => 'Ірина',
            'profile_pic' => 'https://cdn.meta/old.jpg',
            'profile_pic_checked_at' => now()->subDay(),
        ]);
        Http::fake();

        $this->postWebhook($this->igPayload('m_p3'))->assertOk();

        $this->assertSame('https://cdn.meta/old.jpg', InboxContact::where('external_id', 'USER1')->value('profile_pic'));
        Http::assertNothingSent();
    }

    public function test_closed_profile_api_keeps_name_and_stamps_attempt(): void
    {
        $conn = $this->connection();
        InboxContact::create([
            'meta_connection_id' => $conn->id,
            'channel' => 'instagram',
            'external_id' => 'USER1',
            'name' => 'Стара Назва',
            'profile_pic' => null,
            'profile_pic_checked_at' => null,
        ]);
        $this->fakeProfile(null); // Graph віддає (#3) — як для FB зараз

        $this->postWebhook($this->igPayload('m_p4'))->assertOk();

        $contact = InboxContact::where('external_id', 'USER1')->firstOrFail();
        $this->assertSame('Стара Назва', $contact->name, 'невдалий фетч не сміє стерти імʼя');
        $this->assertNull($contact->profile_pic);
        $this->assertNotNull($contact->profile_pic_checked_at, 'спроба мусить тротлитись навіть при відмові');
    }

    public function test_contact_without_pic_is_retried_daily(): void
    {
        // IG інколи відповідає «consent required», а за годину профіль уже відкритий —
        // тому без фото пробуємо щодня (з фото — раз на 7 днів).
        $conn = $this->connection();
        InboxContact::create([
            'meta_connection_id' => $conn->id,
            'channel' => 'instagram',
            'external_id' => 'USER1',
            'name' => 'Ірина',
            'profile_pic' => null,
            'profile_pic_checked_at' => now()->subDays(2),
        ]);
        $this->fakeProfile('https://cdn.meta/late.jpg');

        $this->postWebhook($this->igPayload('m_p6'))->assertOk();

        $this->assertLocalAvatar(InboxContact::where('external_id', 'USER1')->firstOrFail());
    }

    public function test_localize_command_moves_avatars_to_our_domain(): void
    {
        $conn = $this->connection();
        $alive = InboxContact::create([
            'meta_connection_id' => $conn->id, 'channel' => 'instagram',
            'external_id' => 'U_A', 'name' => 'A', 'profile_pic' => 'https://cdn.meta/a.jpg',
        ]);
        $dead = InboxContact::create([
            'meta_connection_id' => $conn->id, 'channel' => 'instagram',
            'external_id' => 'U_B', 'name' => 'B', 'profile_pic' => 'https://dead.meta/b.jpg',
        ]);
        Http::fake([
            'cdn.meta/*' => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
            'dead.meta/*' => Http::response('gone', 404),
        ]);

        $this->artisan('inbox:localize-avatars')->assertExitCode(0);

        $this->assertLocalAvatar($alive->fresh());
        $this->assertSame('https://dead.meta/b.jpg', $dead->fresh()->profile_pic, 'мертвий лінк лишається як був');
    }

    public function test_conversations_endpoint_returns_absolute_local_avatar_url(): void
    {
        $conn = $this->connection();
        $contact = InboxContact::create([
            'meta_connection_id' => $conn->id, 'channel' => 'instagram',
            'external_id' => 'U_C', 'name' => 'C', 'profile_pic' => 'inbox-avatars/c9.jpg?v=abc12345',
        ]);
        \App\Models\InboxConversation::create([
            'meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id,
            'channel' => 'instagram', 'last_message_at' => now(),
        ]);
        $user = \App\Models\User::factory()->create(['role' => 'owner']);

        $resp = $this->actingAs($user)->getJson('/api/inbox/conversations');

        $resp->assertOk();
        $this->assertSame(url('inbox-avatars/c9.jpg?v=abc12345'), $resp->json('data.0.avatar'));
    }
}