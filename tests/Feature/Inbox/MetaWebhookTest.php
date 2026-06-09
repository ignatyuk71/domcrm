<?php

namespace Tests\Feature\Inbox;

use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaWebhookTest extends TestCase
{
    use RefreshDatabase;

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

    private function fbPayload(string $mid, string $text): array
    {
        return [
            'object' => 'page',
            'entry' => [[
                'id' => 'PAGE1',
                'messaging' => [[
                    'sender' => ['id' => 'USER1'],
                    'recipient' => ['id' => 'PAGE1'],
                    'timestamp' => 1700000000000,
                    'message' => ['mid' => $mid, 'text' => $text],
                ]],
            ]],
        ];
    }

    public function test_get_verification_returns_challenge_with_correct_token(): void
    {
        config(['services.meta.verify_token' => 'secret123']);

        $this->get('/api/meta/webhook?hub.mode=subscribe&hub.verify_token=secret123&hub.challenge=42')
            ->assertOk()
            ->assertSee('42');
    }

    public function test_get_verification_rejects_wrong_token(): void
    {
        config(['services.meta.verify_token' => 'secret123']);

        $this->get('/api/meta/webhook?hub.mode=subscribe&hub.verify_token=WRONG&hub.challenge=42')
            ->assertForbidden();
    }

    public function test_incoming_facebook_message_is_stored(): void
    {
        $conn = $this->connection();

        $this->postJson('/api/meta/webhook', $this->fbPayload('m_1', 'Привіт'))->assertOk();

        $this->assertDatabaseHas('inbox_contacts', [
            'meta_connection_id' => $conn->id,
            'channel' => 'facebook',
            'external_id' => 'USER1',
        ]);
        $this->assertDatabaseHas('inbox_messages', [
            'external_message_id' => 'm_1',
            'direction' => 'in',
            'text' => 'Привіт',
        ]);

        $conversation = InboxConversation::first();
        $this->assertSame(1, (int) $conversation->unread_count);
        $this->assertSame('Привіт', $conversation->last_message_text);
    }

    public function test_instagram_message_is_stored_on_linked_account(): void
    {
        $this->connection();

        $payload = [
            'object' => 'instagram',
            'entry' => [[
                'id' => 'IG1',
                'messaging' => [[
                    'sender' => ['id' => 'IGUSER'],
                    'recipient' => ['id' => 'IG1'],
                    'timestamp' => 1700000000000,
                    'message' => ['mid' => 'ig_1', 'text' => 'Хочу замовити'],
                ]],
            ]],
        ];

        $this->postJson('/api/meta/webhook', $payload)->assertOk();

        $this->assertDatabaseHas('inbox_contacts', ['channel' => 'instagram', 'external_id' => 'IGUSER']);
        $this->assertDatabaseHas('inbox_messages', ['external_message_id' => 'ig_1', 'text' => 'Хочу замовити']);
    }

    public function test_duplicate_message_is_ignored(): void
    {
        $this->connection();

        $this->postJson('/api/meta/webhook', $this->fbPayload('m_dup', 'Hi'))->assertOk();
        $this->postJson('/api/meta/webhook', $this->fbPayload('m_dup', 'Hi'))->assertOk();

        $this->assertSame(1, InboxMessage::where('external_message_id', 'm_dup')->count());
    }
}
