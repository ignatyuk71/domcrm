<?php

namespace Tests\Feature\Inbox;

use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InboxControllerTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        return User::factory()->create(['role' => User::ROLE_OPERATOR, 'is_active' => true]);
    }

    private function conversationWithMessage(): InboxConversation
    {
        $conn = MetaConnection::create([
            'page_id' => 'PAGE1', 'page_name' => 'Test Page', 'page_access_token' => 'tok', 'status' => 'active',
        ]);
        $contact = InboxContact::create([
            'meta_connection_id' => $conn->id, 'channel' => 'facebook', 'external_id' => 'USER1', 'name' => 'Іван',
        ]);
        $conv = InboxConversation::create([
            'meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook',
            'last_message_at' => now(), 'last_message_text' => 'Привіт', 'last_message_direction' => 'in', 'unread_count' => 2,
        ]);
        InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm1', 'text' => 'Привіт', 'sent_at' => now(),
        ]);

        return $conv;
    }

    public function test_guest_cannot_access_inbox(): void
    {
        $this->get('/inbox')->assertRedirect();
    }

    public function test_operator_sees_conversations(): void
    {
        $this->conversationWithMessage();

        $this->actingAs($this->operator())
            ->getJson('/api/inbox/conversations')
            ->assertOk()
            ->assertJsonFragment(['store' => 'Test Page', 'contact_name' => 'Іван', 'unread' => 2]);
    }

    public function test_opening_conversation_returns_messages_and_marks_read(): void
    {
        $conv = $this->conversationWithMessage();

        $this->actingAs($this->operator())
            ->getJson("/api/inbox/conversations/{$conv->id}/messages")
            ->assertOk()
            ->assertJsonPath('conversation.contact_name', 'Іван')
            ->assertJsonPath('messages.0.text', 'Привіт');

        $this->assertSame(0, (int) $conv->fresh()->unread_count);
    }

    public function test_send_stores_outgoing_and_calls_send_api(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['message_id' => 'mid_out_1'], 200),
        ]);
        $conv = $this->conversationWithMessage();

        $this->actingAs($this->operator())
            ->postJson("/api/inbox/conversations/{$conv->id}/send", ['text' => 'Дякую за звернення'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message.direction', 'out');

        $this->assertDatabaseHas('inbox_messages', [
            'inbox_conversation_id' => $conv->id,
            'direction' => 'out',
            'text' => 'Дякую за звернення',
            'external_message_id' => 'mid_out_1',
        ]);

        Http::assertSent(fn ($req) => str_contains($req->url(), '/PAGE1/messages'));
    }

    public function test_send_requires_text(): void
    {
        $conv = $this->conversationWithMessage();

        $this->actingAs($this->operator())
            ->postJson("/api/inbox/conversations/{$conv->id}/send", ['text' => ''])
            ->assertStatus(422);
    }
}
