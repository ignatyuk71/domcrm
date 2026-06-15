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

    // --- Дошка замовлень ---

    public function test_guest_cannot_access_board(): void
    {
        $this->get('/orders-board')->assertRedirect();
    }

    public function test_board_page_renders(): void
    {
        $this->actingAs($this->operator())
            ->get('/orders-board')
            ->assertOk()
            ->assertSee('Дошка замовлень');
    }

    public function test_board_shows_only_work_statuses(): void
    {
        $inProgress = \App\Models\ChatStatus::where('code', 'in_progress')->firstOrFail();
        $order = \App\Models\ChatStatus::where('code', 'order')->firstOrFail();
        $ai = \App\Models\ChatStatus::firstOrCreate(['code' => 'ai_order'], ['name' => 'Замовлення від ШІ', 'color' => '#7c3aed', 'sort_order' => 5]);
        $new = \App\Models\ChatStatus::where('code', 'new')->firstOrFail();

        $conn = MetaConnection::create(['page_id' => 'PG', 'page_name' => 'P', 'page_access_token' => 't', 'status' => 'active']);
        $mk = function (int $statusId, string $name) use ($conn) {
            $contact = InboxContact::create(['meta_connection_id' => $conn->id, 'channel' => 'facebook', 'external_id' => 'u' . $name, 'name' => $name]);
            return InboxConversation::create([
                'meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook',
                'last_message_at' => now(), 'last_message_text' => 'msg ' . $name, 'last_message_direction' => 'in',
                'chat_status_id' => $statusId,
            ]);
        };
        $mk($inProgress->id, 'Анна');
        $mk($order->id, 'Богдан');
        $mk($ai->id, 'Віра');
        $mk($new->id, 'Гнат'); // «Новий» НЕ має зʼявитись на дошці

        $res = $this->actingAs($this->operator())->getJson('/api/inbox/board')->assertOk();
        $names = collect($res->json('columns'))->pluck('name');

        // Рівно 3 робочі колонки, у порядку sort_order
        $this->assertSame(['В роботі', 'Замовлення', 'Замовлення від ШІ'], $names->all());
        $this->assertFalse($names->contains('Новий'));
        $this->assertFalse($names->contains('Без статусу'));

        $cols = collect($res->json('columns'))->keyBy('name');
        $this->assertSame(1, $cols['В роботі']['count']);
        $this->assertSame('Анна', $cols['В роботі']['cards'][0]['name']);
    }

    public function test_board_includes_needs_human_first(): void
    {
        $nh = \App\Models\ChatStatus::firstOrCreate(['code' => 'needs_human'], ['name' => 'Потрібна увага', 'color' => '#dc2626', 'sort_order' => 0]);

        $conn = MetaConnection::create(['page_id' => 'PGN', 'page_name' => 'PN', 'page_access_token' => 't', 'status' => 'active']);
        $contact = InboxContact::create(['meta_connection_id' => $conn->id, 'channel' => 'facebook', 'external_id' => 'unh', 'name' => 'Клієнт']);
        InboxConversation::create([
            'meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook',
            'last_message_at' => now(), 'last_message_text' => 'не зрозуміло', 'last_message_direction' => 'in',
            'chat_status_id' => $nh->id,
        ]);

        $res = $this->actingAs($this->operator())->getJson('/api/inbox/board')->assertOk();
        $names = collect($res->json('columns'))->pluck('name');

        // «Потрібна увага» є на дошці й стоїть ПЕРШОЮ (sort_order 0)
        $this->assertSame('Потрібна увага', $names->first());
    }

    public function test_board_card_shows_ai_order_details(): void
    {
        $aiStatus = \App\Models\ChatStatus::firstOrCreate(['code' => 'ai_order'], ['name' => 'Замовлення від ШІ', 'color' => '#7c3aed', 'sort_order' => 5]);

        $conn = MetaConnection::create(['page_id' => 'PG2', 'page_name' => 'P2', 'page_access_token' => 't', 'status' => 'active']);
        $contact = InboxContact::create(['meta_connection_id' => $conn->id, 'channel' => 'instagram', 'external_id' => 'ux', 'name' => 'Раіса']);
        InboxConversation::create([
            'meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'instagram',
            'last_message_at' => now(), 'last_message_text' => 'дякую', 'last_message_direction' => 'in',
            'chat_status_id' => $aiStatus->id,
            'ai_order_summary' => 'Вуличні чорні 38/39 — 530 грн', 'ai_order_phone' => '0966070141',
            'ai_order_needs_iban' => true,
        ]);

        $res = $this->actingAs($this->operator())->getJson('/api/inbox/board')->assertOk();
        $card = collect($res->json('columns'))->firstWhere('name', 'Замовлення від ШІ')['cards'][0];

        $this->assertTrue($card['is_ai_order']);
        $this->assertTrue($card['needs_iban']);
        $this->assertSame('0966070141', $card['phone']);
        $this->assertStringContainsString('Вуличні чорні', $card['snippet']);
    }
}
