<?php

namespace Tests\Feature\Inbox;

use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiSweepTest extends TestCase
{
    use RefreshDatabase;

    private InboxConversation $conv;

    private function setUpConversation(): void
    {
        $conn = MetaConnection::create(['page_id' => 'P_SW', 'page_name' => 'Shop', 'page_access_token' => 'tok', 'status' => 'active']);
        AiSetting::global()->update(['api_key' => 'sk-ant-test', 'model' => 'claude-sonnet-4-6']);
        AiSetting::forConnection($conn->id)->update(['enabled' => true, 'system_prompt' => 'Ти продавець.']);
        $contact = InboxContact::create(['meta_connection_id' => $conn->id, 'channel' => 'facebook', 'external_id' => 'U_SW']);
        $this->conv = InboxConversation::create(['meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook']);
    }

    private function oldIncoming(string $text, int $minutesAgo = 5): InboxMessage
    {
        $m = InboxMessage::create([
            'inbox_conversation_id' => $this->conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_sw_' . uniqid(), 'text' => $text, 'sent_at' => now()->subMinutes($minutesAgo),
        ]);
        $m->created_at = now()->subMinutes($minutesAgo);
        $m->save();

        return $m;
    }

    public function test_sweep_answers_message_whose_background_job_died(): void
    {
        $this->setUpConversation();
        $this->oldIncoming('Яка ціна?'); // фоновий процес «вбили» — жодного AiRun

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Вітаю! Ціни від 380 грн 🙂']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 500, 'output_tokens' => 20],
            ], 200),
            'graph.facebook.com/*' => Http::response(['message_id' => 'm_sw_out'], 200),
        ]);

        $this->artisan('ai:sweep')->assertSuccessful();

        $this->assertDatabaseHas('ai_runs', ['inbox_conversation_id' => $this->conv->id, 'status' => 'replied']);
        $this->assertDatabaseHas('inbox_messages', ['sender' => 'ai', 'text' => 'Вітаю! Ціни від 380 грн 🙂']);
    }

    public function test_sweep_skips_fresh_already_handled_and_disabled(): void
    {
        $this->setUpConversation();

        // 1) свіже (швидкий шлях ще може жити)
        InboxMessage::create([
            'inbox_conversation_id' => $this->conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_fresh_sw', 'text' => 'щойно написав', 'sent_at' => now(),
        ]);

        // 2) старе, але вже має AiRun
        $handled = $this->oldIncoming('оброблене');
        AiRun::create(['inbox_conversation_id' => $this->conv->id, 'inbox_message_id' => $handled->id, 'status' => 'replied']);

        Http::fake();
        $this->artisan('ai:sweep')->expectsOutputToContain('Оброблено: 0')->assertSuccessful();
        Http::assertNothingSent();

        // 3) ШІ вимкнений у розмові
        $this->conv->update(['ai_enabled' => false]);
        $this->oldIncoming('без ші');
        $this->artisan('ai:sweep')->expectsOutputToContain('Оброблено: 0')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_sweep_records_stale_when_operator_already_replied(): void
    {
        $this->setUpConversation();
        $missed = $this->oldIncoming('Є 41 розмір?');

        // Оператор відповів сам (як було з Ксюшею)
        InboxMessage::create([
            'inbox_conversation_id' => $this->conv->id, 'direction' => 'out', 'sender' => 'agent',
            'external_message_id' => 'm_op_sw', 'text' => 'завтра подивлюсь', 'sent_at' => now(),
        ]);

        Http::fake();
        $this->artisan('ai:sweep')->assertSuccessful();

        // Зафіксовано skip — клієнта не турбуємо, але повторно не підбираємо
        $this->assertDatabaseHas('ai_runs', ['inbox_message_id' => $missed->id, 'status' => 'skipped_stale']);
        $this->assertSame(0, InboxMessage::where('sender', 'ai')->count());
        Http::assertNothingSent();
    }

    public function test_message_lock_prevents_double_reply(): void
    {
        $this->setUpConversation();
        $msg = $this->oldIncoming('подвійний?');

        // «Швидкий шлях» ще тримає замок
        Cache::lock('ai-respond-msg-' . $msg->id, 300)->get();

        Http::fake();
        $this->artisan('ai:sweep')->assertSuccessful();

        $this->assertDatabaseHas('ai_runs', ['inbox_message_id' => $msg->id, 'status' => 'skipped_in_progress']);
        Http::assertNothingSent();
    }

    public function test_sweep_retries_message_that_errored(): void
    {
        $this->setUpConversation();
        $msg = $this->oldIncoming('Привіт');
        // Попередня спроба впала з помилкою (раніше таке зависало назавжди)
        AiRun::create(['inbox_conversation_id' => $this->conv->id, 'inbox_message_id' => $msg->id, 'status' => 'error', 'error' => 'Claude: temperature ...']);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Вітаю! 🙂']],
                'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 100, 'output_tokens' => 10],
            ], 200),
            'graph.facebook.com/*' => Http::response(['message_id' => 'm_retry'], 200),
        ]);

        $this->artisan('ai:sweep')->assertSuccessful();

        // Крон добрав повідомлення, що раніше впало, і відповів
        $this->assertDatabaseHas('ai_runs', ['inbox_message_id' => $msg->id, 'status' => 'replied']);
    }

    public function test_sweep_gives_up_after_three_errors(): void
    {
        $this->setUpConversation();
        $msg = $this->oldIncoming('Привіт');
        for ($i = 0; $i < 3; $i++) {
            AiRun::create(['inbox_conversation_id' => $this->conv->id, 'inbox_message_id' => $msg->id, 'status' => 'error', 'error' => 'x']);
        }

        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'hi']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]], 200),
            'graph.facebook.com/*' => Http::response(['message_id' => 'x'], 200),
        ]);

        $this->artisan('ai:sweep')->assertSuccessful();

        // 3 невдалі спроби вже були — більше не довбимо (Claude не викликається)
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'api.anthropic.com'));
    }
}
