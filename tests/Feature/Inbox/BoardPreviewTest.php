<?php

namespace Tests\Feature\Inbox;

use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Легке прев'ю чату для наведення на картку дошки: останні повідомлення,
 * БЕЗ позначення прочитаним (щоб лічильник непрочитаних не збивався).
 */
class BoardPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function conv(): InboxConversation
    {
        $conn = MetaConnection::create(['page_id' => 'P_PV', 'page_name' => 'Shop', 'page_access_token' => 'tok', 'status' => 'active']);
        $contact = InboxContact::create(['meta_connection_id' => $conn->id, 'channel' => 'facebook', 'external_id' => 'U_PV', 'name' => 'Ярослав']);

        return InboxConversation::create([
            'meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook',
            'unread_count' => 3,
        ]);
    }

    public function test_preview_returns_recent_messages_and_name(): void
    {
        $conv = $this->conv();
        InboxMessage::create(['inbox_conversation_id' => $conv->id, 'direction' => 'in', 'sender' => 'contact', 'external_message_id' => 'p1', 'text' => 'Пудрові є?', 'sent_at' => now()->subMinutes(3)]);
        InboxMessage::create(['inbox_conversation_id' => $conv->id, 'direction' => 'out', 'sender' => 'ai', 'external_message_id' => 'p2', 'text' => 'Так, є 🙂', 'sent_at' => now()->subMinutes(2)]);
        InboxMessage::create(['inbox_conversation_id' => $conv->id, 'direction' => 'out', 'sender' => 'ai', 'external_message_id' => 'p3', 'text' => null, 'attachments' => [['type' => 'image', 'url' => 'x']], 'sent_at' => now()->subMinute()]);

        $user = User::factory()->create(['role' => 'owner']);
        $resp = $this->actingAs($user)->getJson("/api/inbox/conversations/{$conv->id}/preview");

        $resp->assertOk()
            ->assertJsonPath('name', 'Ярослав')
            ->assertJsonPath('messages.0.text', 'Пудрові є?')
            ->assertJsonPath('messages.1.sender', 'ai')
            ->assertJsonPath('messages.2.has_photo', true);

        // Головне: прев'ю НЕ позначає прочитаним.
        $this->assertSame(3, $conv->fresh()->unread_count);
    }

    public function test_preview_caps_at_last_ten(): void
    {
        $conv = $this->conv();
        for ($i = 1; $i <= 14; $i++) {
            InboxMessage::create(['inbox_conversation_id' => $conv->id, 'direction' => 'in', 'sender' => 'contact', 'external_message_id' => "m$i", 'text' => "msg $i", 'sent_at' => now()->addSeconds($i)]);
        }

        $user = User::factory()->create(['role' => 'owner']);
        $resp = $this->actingAs($user)->getJson("/api/inbox/conversations/{$conv->id}/preview");

        $resp->assertOk();
        $this->assertCount(10, $resp->json('messages'));
        // Останні 10 у хронологічному порядку: msg 5 … msg 14.
        $this->assertSame('msg 5', $resp->json('messages.0.text'));
        $this->assertSame('msg 14', $resp->json('messages.9.text'));
    }
}
