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

        app(AiAgentService::class)->toolCompleteOrder($conv, [
            'summary' => 'Домашні пухнасті, чорні, 36/37',
            'payment' => 'на карту',
            'product_id' => 42,
        ]);

        $conv->refresh();
        $this->assertSame('Домашні пухнасті, чорні, 36/37', $conv->ai_order_summary);
        $this->assertSame('на карту', $conv->ai_order_payment);
        $this->assertSame(42, (int) $conv->ai_order_product_id);
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
}
