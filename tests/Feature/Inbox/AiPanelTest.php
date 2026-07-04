<?php

namespace Tests\Feature\Inbox;

use App\Models\AiSetting;
use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
use App\Models\User;
use App\Services\Ai\AiAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiPanelTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(): InboxConversation
    {
        $conn = MetaConnection::create([
            'page_id' => 'PAGE1', 'page_name' => 'Test Shop', 'page_access_token' => 'tok', 'status' => 'active',
        ]);
        $contact = InboxContact::create([
            'meta_connection_id' => $conn->id, 'channel' => 'facebook', 'external_id' => 'USER1', 'name' => 'Іван',
        ]);

        return InboxConversation::create([
            'meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook',
        ]);
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => User::ROLE_OWNER, 'is_active' => true]);
    }

    public function test_complete_order_stores_details_for_panel(): void
    {
        $conv = $this->conversation();

        // Без items, лише summary → ai_order_summary заповнюється через fallback.
        app(AiAgentService::class)->toolCompleteOrder($conv, [
            'summary' => 'Домашні пухнасті, чорні, 36/37',
            'payment' => 'на карту',
        ]);

        $conv->refresh();
        $this->assertSame('Домашні пухнасті, чорні, 36/37', $conv->ai_order_summary);
        $this->assertSame('на карту', $conv->ai_order_payment);
        $this->assertNull($conv->ai_order_handled_at);
        $this->assertFalse((bool) $conv->ai_enabled); // бот замовкає після замовлення
    }

    public function test_summarize_conversation_calls_claude_and_caches(): void
    {
        AiSetting::global()->update(['api_key' => 'sk-ant-test', 'model' => 'claude-sonnet-4-6']);
        $conv = $this->conversation();
        InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'in', 'sender' => 'contact',
            'text' => 'Скільки коштують білі 38?', 'sent_at' => now(),
        ]);

        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Клієнт питає ціну білих 38 розміру.']],
            'usage' => ['input_tokens' => 50, 'output_tokens' => 12],
        ], 200)]);

        $summary = app(AiAgentService::class)->summarizeConversation($conv);

        $this->assertSame('Клієнт питає ціну білих 38 розміру.', $summary);
        $this->assertSame('Клієнт питає ціну білих 38 розміру.', $conv->fresh()->ai_summary);
        $this->assertNotNull($conv->fresh()->ai_summary_at);
    }

    public function test_summarize_returns_null_without_api_key(): void
    {
        AiSetting::global()->update(['api_key' => null]);
        $conv = $this->conversation();
        InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'in', 'sender' => 'contact',
            'text' => 'Привіт', 'sent_at' => now(),
        ]);

        $this->assertNull(app(AiAgentService::class)->summarizeConversation($conv));
    }

    public function test_summary_endpoint_returns_summary(): void
    {
        AiSetting::global()->update(['api_key' => 'sk-ant-test']);
        $conv = $this->conversation();
        InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'in', 'sender' => 'contact',
            'text' => 'Є 39?', 'sent_at' => now(),
        ]);

        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Питає наявність 39.']],
        ], 200)]);

        $this->actingAs($this->owner())
            ->postJson("/api/inbox/conversations/{$conv->id}/ai-summary")
            ->assertOk()
            ->assertJson(['ok' => true, 'summary' => 'Питає наявність 39.']);
    }

    public function test_mark_order_handled_endpoint(): void
    {
        $conv = $this->conversation();
        $conv->update(['ai_order_summary' => 'Вуличні білі 38', 'ai_order_payment' => 'на карту']);

        $this->actingAs($this->owner())
            ->postJson("/api/inbox/conversations/{$conv->id}/ai-order-handled")
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertNotNull($conv->fresh()->ai_order_handled_at);
    }

    public function test_messages_endpoint_exposes_ai_order_and_summary(): void
    {
        $conv = $this->conversation();
        $conv->update([
            'ai_order_summary' => 'Домашні чорні 37',
            'ai_order_payment' => 'при отриманні',
            'ai_summary' => 'Замовив домашні чорні.',
        ]);
        InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'in', 'sender' => 'contact',
            'text' => 'Хочу замовити', 'sent_at' => now(),
        ]);

        $res = $this->actingAs($this->owner())
            ->getJson("/api/inbox/conversations/{$conv->id}/messages")
            ->assertOk()->json('conversation');

        $this->assertSame('Замовив домашні чорні.', $res['ai_summary']);
        $this->assertSame('Домашні чорні 37', $res['ai_order']['summary']);
        $this->assertSame('при отриманні', $res['ai_order']['payment']);
        $this->assertFalse($res['ai_order']['handled']);
    }

    public function test_ask_delivery_details_sends_template(): void
    {
        $conv = $this->conversation();
        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'm_d1'], 200)]);

        app(AiAgentService::class)->toolAskDeliveryDetails($conv);

        $msg = InboxMessage::where('inbox_conversation_id', $conv->id)->where('direction', 'out')->latest()->first();
        $this->assertNotNull($msg);
        $this->assertStringContainsString('Доставка до вашого відділення', (string) $msg->text);
        $this->assertStringContainsString('ПІБ', (string) $msg->text);
    }

    public function test_send_payment_details_sends_both_card_and_iban_separately(): void
    {
        $conv = $this->conversation();
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['message_id' => 'm_p1'], 200)
            ->push(['message_id' => 'm_p2'], 200)
            ->push(['message_id' => 'm_p3'], 200)
            ->push(['message_id' => 'm_p4'], 200)]);

        $res = app(AiAgentService::class)->toolSendPaymentDetails($conv, ['amount' => 530]);

        // Сторож проти повторної форми доставки (кейс Алли): підказка моделі
        // мусить прямо забороняти передрук списку ПІБ/телефон/адреса.
        $this->assertStringContainsString('ПОВТОРНО НЕ друкуй', $res['готово']);
        $this->assertStringContainsString('якщо клієнт їх УЖЕ надав — нічого не проси', $res['готово']);

        $out = InboxMessage::where('inbox_conversation_id', $conv->id)->where('direction', 'out')->get();
        $this->assertCount(4, $out); // картка: підпис + номер; ФОП: реквізити + IBAN
        // Номер картки й IBAN — рівно з конфігу, кожен ОКРЕМИМ повідомленням (зручно копіювати, модель їх не друкує).
        $this->assertDatabaseHas('inbox_messages', [
            'inbox_conversation_id' => $conv->id, 'text' => '5169335107343648',
        ]);
        $this->assertDatabaseHas('inbox_messages', [
            'inbox_conversation_id' => $conv->id, 'text' => 'UA133052990000026009010716418',
        ]);
        $this->assertTrue($out->contains(fn ($m) => str_contains((string) $m->text, 'ПриватБанк')));
        // Сума підставлена в реквізити ФОП.
        $this->assertTrue($out->contains(fn ($m) => str_contains((string) $m->text, 'Сума до оплати: 530 грн')));
    }

    public function test_send_payment_details_requires_amount(): void
    {
        $conv = $this->conversation();
        $res = app(AiAgentService::class)->toolSendPaymentDetails($conv, []);
        $this->assertArrayHasKey('помилка', $res);
        $this->assertSame(0, InboxMessage::where('inbox_conversation_id', $conv->id)->where('direction', 'out')->count());
    }

    public function test_complete_order_stores_items_and_delivery(): void
    {
        $conv = $this->conversation();

        app(AiAgentService::class)->toolCompleteOrder($conv, [
            'items' => [
                ['title' => 'Домашні пухнасті', 'color' => 'чорні', 'size' => '38', 'qty' => 1, 'price' => 530],
                ['title' => 'Вуличні', 'color' => 'білі', 'size' => '40', 'qty' => 2],
            ],
            'customer_name' => 'Іваненко Іван Іванович',
            'phone' => '0961234567',
            'address' => 'Київ, відділення №5',
            'payment' => 'на карту',
        ]);

        $conv->refresh();
        $this->assertCount(2, $conv->ai_order_items);
        $this->assertSame('Домашні пухнасті', $conv->ai_order_items[0]['title']);
        $this->assertSame(2, $conv->ai_order_items[1]['qty']);
        $this->assertSame('Іваненко Іван Іванович', $conv->ai_order_customer_name);
        $this->assertSame('0961234567', $conv->ai_order_phone);
        $this->assertSame('Київ, відділення №5', $conv->ai_order_address);
        $this->assertSame('на карту', $conv->ai_order_payment);
        $this->assertFalse((bool) $conv->ai_enabled);
        $this->assertNotEmpty($conv->ai_order_summary);
    }

    public function test_messages_exposes_new_ai_order_fields(): void
    {
        $conv = $this->conversation();
        $conv->update([
            'ai_order_items' => [['title' => 'Домашні', 'color' => 'чорні', 'size' => '38', 'qty' => 1, 'price' => 530]],
            'ai_order_summary' => 'Домашні чорні 38',
            'ai_order_customer_name' => 'Іван',
            'ai_order_phone' => '0961234567',
            'ai_order_address' => 'Київ №5',
            'ai_order_payment' => 'на карту',
        ]);
        InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'in', 'sender' => 'contact',
            'text' => 'привіт', 'sent_at' => now(),
        ]);

        $order = $this->actingAs($this->owner())
            ->getJson("/api/inbox/conversations/{$conv->id}/messages")
            ->assertOk()->json('conversation.ai_order');

        $this->assertCount(1, $order['items']);
        $this->assertSame('Іван', $order['customer_name']);
        $this->assertSame('Київ №5', $order['address']);
        $this->assertFalse($order['needs_iban']);
    }

    public function test_operator_reply_clears_iban_flag(): void
    {
        $conv = $this->conversation();
        $conv->update(['ai_order_needs_iban' => true]);
        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'm_op'], 200)]);

        $this->actingAs($this->owner())
            ->postJson("/api/inbox/conversations/{$conv->id}/send", ['text' => 'Ось повні реквізити: UA...'])
            ->assertOk();

        $this->assertFalse((bool) $conv->fresh()->ai_order_needs_iban);
    }

    public function test_webhook_read_event_sets_last_read_at(): void
    {
        config(['services.meta.app_secret' => '']); // вимкнути перевірку підпису в тесті
        $conv = $this->conversation();
        $ts = (int) (now()->valueOf()); // мс

        $payload = [
            'object' => 'page',
            'entry' => [[
                'id' => 'PAGE1',
                'messaging' => [[
                    'sender' => ['id' => 'USER1'],     // клієнт, що прочитав
                    'recipient' => ['id' => 'PAGE1'],  // наша сторінка
                    'timestamp' => $ts,
                    'read' => ['watermark' => $ts],
                ]],
            ]],
        ];

        $this->postMetaWebhook($payload)->assertOk();

        $this->assertNotNull($conv->fresh()->last_read_at);
    }

    public function test_messages_marks_seen_when_last_out_message_read(): void
    {
        $conv = $this->conversation();
        $msg = InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'out', 'sender' => 'ai',
            'text' => 'Вітаю!', 'sent_at' => now()->subMinutes(5),
        ]);
        $conv->update(['last_read_at' => now()->subMinute()]); // прочитано пізніше, ніж відправлено

        $res = $this->actingAs($this->owner())
            ->getJson("/api/inbox/conversations/{$conv->id}/messages")
            ->assertOk()->json('conversation');

        $this->assertSame($msg->id, $res['last_out_id']); // галочки на цьому повідомленні
        $this->assertTrue($res['last_out_read']);         // прочитане → сині
    }

    public function test_messages_not_seen_when_out_message_after_read(): void
    {
        $conv = $this->conversation();
        $conv->update(['last_read_at' => now()->subMinutes(5)]);
        InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'out', 'sender' => 'ai',
            'text' => 'Нове повідомлення', 'sent_at' => now(), // після прочитання
        ]);

        $res = $this->actingAs($this->owner())
            ->getJson("/api/inbox/conversations/{$conv->id}/messages")
            ->assertOk()->json('conversation');

        $this->assertFalse($res['last_out_read']); // останнє новіше за прочитане → сірі
    }

    public function test_sweep_sends_follow_up_to_silent_lead(): void
    {
        AiSetting::global()->update(['follow_up_hours' => 3]);
        $conn = MetaConnection::create(['page_id' => 'PAGE1', 'page_name' => 'Shop', 'page_access_token' => 'tok', 'status' => 'active']);
        AiSetting::forConnection($conn->id)->update(['enabled' => true]);
        $contact = InboxContact::create(['meta_connection_id' => $conn->id, 'channel' => 'facebook', 'external_id' => 'USER1', 'name' => 'Ліда']);
        $conv = InboxConversation::create([
            'meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook',
            'ai_enabled' => true, 'last_message_direction' => 'out', 'last_message_at' => now()->subHours(4),
        ]);
        // Клієнт писав у DM (вікно 24г відкрите), ми відповіли, клієнт замовк.
        InboxMessage::create(['inbox_conversation_id' => $conv->id, 'direction' => 'in', 'sender' => 'contact', 'text' => 'ціна?', 'sent_at' => now()->subHours(13)]);
        InboxMessage::create(['inbox_conversation_id' => $conv->id, 'direction' => 'out', 'sender' => 'ai', 'text' => '380 грн', 'sent_at' => now()->subHours(4)]);

        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'm_fu'], 200)]);

        $this->artisan('ai:sweep')->assertExitCode(0);

        $this->assertNotNull($conv->fresh()->follow_up_sent_at);
        $this->assertDatabaseHas('inbox_messages', [
            'inbox_conversation_id' => $conv->id, 'sender' => 'ai',
            'text' => AiAgentService::orderTexts()['follow_up'],
        ]);
    }

    public function test_sweep_skips_follow_up_without_client_dm(): void
    {
        AiSetting::global()->update(['follow_up_hours' => 3]);
        $conn = MetaConnection::create(['page_id' => 'PAGE2', 'page_name' => 'Shop', 'page_access_token' => 'tok', 'status' => 'active']);
        AiSetting::forConnection($conn->id)->update(['enabled' => true]);
        $contact = InboxContact::create(['meta_connection_id' => $conn->id, 'channel' => 'facebook', 'external_id' => 'USER2']);
        $conv = InboxConversation::create([
            'meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook',
            'ai_enabled' => true, 'last_message_direction' => 'out', 'last_message_at' => now()->subHours(4),
        ]);
        // Лише наш опенер — клієнт у DM не писав (вікно для повторного DM закрите).
        InboxMessage::create(['inbox_conversation_id' => $conv->id, 'direction' => 'out', 'sender' => 'ai', 'text' => 'опенер', 'sent_at' => now()->subHours(4)]);

        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'm_x'], 200)]);

        $this->artisan('ai:sweep')->assertExitCode(0);

        $this->assertNull($conv->fresh()->follow_up_sent_at);
    }
}
