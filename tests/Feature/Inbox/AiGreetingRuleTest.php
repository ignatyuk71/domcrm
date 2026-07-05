<?php

namespace Tests\Feature\Inbox;

use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
use App\Services\Ai\AiAgentService;
use App\Services\Ai\HistoryBuilder;
use App\Services\Ai\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Сторож проти повторних привітань (аудит 06.07: 14 із 30 діалогів мали 2-3
 * «Доброго дня») і сторож годинника для моделі («Доброго дня!» о 22:26).
 */
class AiGreetingRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_has_single_greeting_rule(): void
    {
        $conn = MetaConnection::create(['page_id' => 'P_GR', 'page_name' => 'Shop', 'page_access_token' => 'tok', 'status' => 'active']);
        $contact = InboxContact::create(['meta_connection_id' => $conn->id, 'channel' => 'facebook', 'external_id' => 'U_GR']);
        $conv = InboxConversation::create(['meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook']);

        $system = collect(app(PromptBuilder::class)->buildSystemPrompt($conv))
            ->pluck('text')->implode("\n");

        $this->assertStringContainsString('ПРИВІТАННЯ — РІВНО ОДНЕ НА РОЗМОВУ', $system);
        $this->assertStringContainsString('включно з авто-відповіддю на коментар', $system);
        $this->assertStringContainsString('після паузи понад 3 дні — привітайся знову', $system);
        // Старе формулювання, що змушувало вітатися вдруге після комент-DM, — прибране.
        $this->assertStringNotContainsString('ПЕРШУ свою відповідь у розмові ОБОВ\'ЯЗКОВО починай з привітання', $system);
    }

    public function test_clock_note_reaches_model_in_last_user_message(): void
    {
        $conn = MetaConnection::create(['page_id' => 'P_GR2', 'page_name' => 'Shop', 'page_access_token' => 'tok', 'status' => 'active']);
        \App\Models\AiSetting::global()->update(['api_key' => 'sk-ant-test', 'model' => 'claude-sonnet-5']);
        \App\Models\AiSetting::forConnection($conn->id)->update(['enabled' => true, 'system_prompt' => 'Ти продавець.']);
        $contact = InboxContact::create(['meta_connection_id' => $conn->id, 'channel' => 'facebook', 'external_id' => 'U_GR2']);
        $conv = InboxConversation::create(['meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook']);
        $msg = InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_gr1', 'text' => 'яка ціна', 'sent_at' => now(),
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Доброго дня! 399 грн 🙂']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 200, 'output_tokens' => 15],
            ], 200),
            'graph.facebook.com/*' => Http::response(['message_id' => 'm_gr_out'], 200),
        ]);

        (new \App\Jobs\AiRespondToMessage($conv->id, $msg->id))->handle(app(AiAgentService::class));

        $req = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->first(fn ($r) => str_contains($r->url(), 'api.anthropic.com'));
        $this->assertNotNull($req);
        $body = json_encode($req->data(), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('(система: зараз ', $body);
        // Без наказу «вітайся» (він змушував Sonnet вітатись щоходу).
        $this->assertStringNotContainsString('Вітайся відповідно до часу доби', $body);
        $this->assertStringContainsString('НЕ вітайся знову', $body);
        $this->assertStringContainsString(now()->format('d.m.Y'), $body);
    }

    public function test_clock_note_lands_on_last_user_message_only(): void
    {
        $conn = MetaConnection::create(['page_id' => 'P_GR3', 'page_name' => 'Shop', 'page_access_token' => 'tok', 'status' => 'active']);
        $contact = InboxContact::create(['meta_connection_id' => $conn->id, 'channel' => 'facebook', 'external_id' => 'U_GR3']);
        $conv = InboxConversation::create(['meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook']);
        InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_gr2', 'text' => 'Привіт', 'sent_at' => now()->subMinutes(5),
        ]);
        InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'out', 'sender' => 'ai',
            'external_message_id' => 'm_gr3', 'text' => 'Доброго дня! Чим допомогти?', 'sent_at' => now()->subMinutes(4),
        ]);
        InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_gr4', 'text' => 'ціна?', 'sent_at' => now(),
        ]);

        $history = app(HistoryBuilder::class)->buildHistory($conv);

        $json = json_encode($history, JSON_UNESCAPED_UNICODE);
        $this->assertSame(1, substr_count($json, '(система: зараз '), 'годинник — рівно один');
        // І саме в ОСТАННЬОМУ повідомленні (user).
        $lastJson = json_encode(end($history), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('(система: зараз ', $lastJson);
        $this->assertSame('user', end($history)['role']);
    }
}