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

    private function fakeProfile(?string $pic): void
    {
        Http::fake([
            'graph.facebook.com/*/USER1*' => $pic
                ? Http::response(['name' => 'Ірина', 'username' => 'iryna', 'profile_pic' => $pic])
                : Http::response(['error' => ['code' => 3, 'message' => 'capability']], 400),
            'graph.facebook.com/*' => Http::response([]),
        ]);
    }

    public function test_new_contact_gets_avatar_and_checked_stamp(): void
    {
        $conn = $this->connection();
        $this->fakeProfile('https://cdn.meta/pic1.jpg');

        $this->postWebhook($this->igPayload('m_p1'))->assertOk();

        $contact = InboxContact::where('external_id', 'USER1')->firstOrFail();
        $this->assertSame('https://cdn.meta/pic1.jpg', $contact->profile_pic);
        $this->assertNotNull($contact->profile_pic_checked_at);
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
        $this->assertSame('https://cdn.meta/fresh.jpg', $contact->profile_pic);
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
}