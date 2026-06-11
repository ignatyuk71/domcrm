<?php

namespace Tests\Feature\Inbox;

use App\Jobs\AiRespondToMessage;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
use App\Services\Ai\AiAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiAgentTest extends TestCase
{
    use RefreshDatabase;

    private MetaConnection $conn;
    private InboxConversation $conv;
    private InboxMessage $incoming;

    private function setUpConversation(bool $withKey = true, bool $storeEnabled = true): void
    {
        $this->conn = MetaConnection::create([
            'page_id' => 'PAGE1', 'page_name' => 'Test Shop', 'page_access_token' => 'tok', 'status' => 'active',
        ]);

        if ($withKey) {
            AiSetting::global()->update(['api_key' => 'sk-ant-test', 'model' => 'claude-sonnet-4-6']);
        }
        AiSetting::forConnection($this->conn->id)->update([
            'enabled' => $storeEnabled,
            'system_prompt' => 'Ти продавець Test Shop.',
        ]);

        $contact = InboxContact::create([
            'meta_connection_id' => $this->conn->id, 'channel' => 'facebook', 'external_id' => 'USER1', 'name' => 'Іван',
        ]);
        $this->conv = InboxConversation::create([
            'meta_connection_id' => $this->conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook',
        ]);
        $this->incoming = InboxMessage::create([
            'inbox_conversation_id' => $this->conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_in_1', 'text' => 'Скільки коштує доставка?', 'sent_at' => now(),
        ]);
    }

    private function runJob(): void
    {
        (new AiRespondToMessage($this->conv->id, $this->incoming->id))->handle(app(AiAgentService::class));
    }

    public function test_ai_replies_and_stores_message(): void
    {
        $this->setUpConversation();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Вітаю! Доставка Новою поштою.']],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
            ], 200),
            'graph.facebook.com/*' => Http::response(['message_id' => 'm_ai_1'], 200),
        ]);

        $this->runJob();

        $this->assertDatabaseHas('inbox_messages', [
            'inbox_conversation_id' => $this->conv->id,
            'direction' => 'out',
            'sender' => 'ai',
            'text' => 'Вітаю! Доставка Новою поштою.',
            'external_message_id' => 'm_ai_1',
        ]);
        $this->assertDatabaseHas('ai_runs', [
            'inbox_conversation_id' => $this->conv->id,
            'status' => 'replied',
            'tokens_in' => 100,
            'tokens_out' => 20,
        ]);
        $this->assertSame('out', $this->conv->fresh()->last_message_direction);

        // Перевіряємо, що в Claude пішла історія і system prompt (json екранує юнікод)
        Http::assertSent(function ($request) {
            $prompt = trim((string) json_encode('Ти продавець Test Shop.'), '"');
            $question = trim((string) json_encode('Скільки коштує доставка?'), '"');

            return str_contains($request->url(), 'api.anthropic.com')
                && str_contains($request->body(), $prompt)
                && str_contains($request->body(), $question);
        });
    }

    public function test_skips_when_conversation_ai_off(): void
    {
        $this->setUpConversation();
        $this->conv->update(['ai_enabled' => false]);
        Http::fake();

        $this->runJob();

        $this->assertDatabaseHas('ai_runs', ['status' => 'skipped_conversation_off']);
        $this->assertDatabaseMissing('inbox_messages', ['sender' => 'ai']);
        Http::assertNothingSent();
    }

    public function test_skips_without_api_key(): void
    {
        $this->setUpConversation(withKey: false);
        Http::fake();

        $this->runJob();

        $this->assertDatabaseHas('ai_runs', ['status' => 'skipped_no_key']);
        Http::assertNothingSent();
    }

    public function test_skips_when_store_disabled(): void
    {
        $this->setUpConversation(storeEnabled: false);
        Http::fake();

        $this->runJob();

        $this->assertDatabaseHas('ai_runs', ['status' => 'skipped_store_off']);
        Http::assertNothingSent();
    }

    public function test_skips_stale_when_newer_message_arrived(): void
    {
        $this->setUpConversation();
        // Клієнт встиг написати ще одне — стара джоба має змовчати
        InboxMessage::create([
            'inbox_conversation_id' => $this->conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_in_2', 'text' => 'І ще питання', 'sent_at' => now(),
        ]);
        Http::fake();

        $this->runJob();

        $this->assertDatabaseHas('ai_runs', ['status' => 'skipped_stale']);
        Http::assertNothingSent();
    }

    public function test_catalog_in_prompt_carries_facts_and_hides_cost(): void
    {
        $this->setUpConversation();

        // Товар у базі: ціна продажу 530, собівартість 777 (унікальна, щоб перевірити витік)
        $product = \App\Models\Product::create([
            'title' => 'капці для вулиці рожеві',
            'sku' => '6023',
            'sale_price' => 530,
            'cost_price' => 777,
            'currency' => 'UAH',
            'description' => 'хутряні капці',
            'is_active' => true,
        ]);
        \App\Models\ProductVariant::create(['product_id' => $product->id, 'size' => '36-37', 'sku' => '6023-36-37', 'stock_qty' => 5, 'is_active' => true]);
        \App\Models\ProductVariant::create(['product_id' => $product->id, 'size' => '38-39', 'sku' => '6023-38-39', 'stock_qty' => 0, 'is_active' => true]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Є рожеві капці, 530 грн, розмір 36-37 в наявності 🙂']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 900, 'output_tokens' => 50],
            ], 200),
            'graph.facebook.com/*' => Http::response(['message_id' => 'm_ai_2'], 200),
        ]);

        $this->runJob();

        $this->assertDatabaseHas('inbox_messages', [
            'sender' => 'ai',
            'text' => 'Є рожеві капці, 530 грн, розмір 36-37 в наявності 🙂',
        ]);

        // У ПЕРШИЙ же запит пішов каталог: назва, ціна, наявні розміри — а собівартість НІ
        $first = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.anthropic.com'))
            ->first();
        $body = $first[0]->body();
        $this->assertStringContainsString(trim((string) json_encode('КАТАЛОГ МАГАЗИНУ'), '"'), $body);
        $this->assertStringContainsString(trim((string) json_encode('капці для вулиці рожеві'), '"'), $body);
        $this->assertStringContainsString('530', $body);
        $this->assertStringNotContainsString('777', $body);
        // Розмір з нульовим залишком не у списку наявних
        $this->assertStringContainsString(trim((string) json_encode('розміри: 36-37'), '"'), $body);
        // Правило про подвійну розмірну сітку присутнє
        $this->assertStringContainsString(trim((string) json_encode('38-39?'), '"'), $body);
        // Каталог кешується (cache_control на блоці)
        $this->assertStringContainsString('cache_control', $body);
    }

    public function test_image_placeholder_is_stripped_from_reply(): void
    {
        $this->setUpConversation();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => "[зображення]\n[фото]\nОсь рожеві капці — 380 грн 💗"]],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
            ], 200),
            'graph.facebook.com/*' => Http::response(['message_id' => 'm_strip'], 200),
        ]);

        $this->runJob();

        $msg = InboxMessage::where('sender', 'ai')->latest('id')->first();
        $this->assertSame('Ось рожеві капці — 380 грн 💗', $msg->text);
        $this->assertStringNotContainsString('[зображення]', $msg->text);
    }

    public function test_agent_reads_description_via_get_product(): void
    {
        $this->setUpConversation();

        $product = \App\Models\Product::create([
            'title' => 'капці для вулиці рожеві',
            'sku' => '6023',
            'sale_price' => 530,
            'currency' => 'UAH',
            'description' => 'верх — екохутро, підошва ЕВА',
            'is_active' => true,
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'content' => [[
                        'type' => 'tool_use', 'id' => 'tu_1',
                        'name' => 'get_product', 'input' => ['product_id' => $product->id],
                    ]],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 300, 'output_tokens' => 40],
                ], 200)
                ->push([
                    'content' => [['type' => 'text', 'text' => 'Підошва ЕВА, верх екохутро 🙂']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 420, 'output_tokens' => 50],
                ], 200),
            'graph.facebook.com/*' => Http::response(['message_id' => 'm_ai_3'], 200),
        ]);

        $this->runJob();

        $run = AiRun::where('status', 'replied')->latest('id')->first();
        $this->assertSame('get_product', $run->tools_called[0]['tool'] ?? null);

        // Опис пішов у другий запит
        $second = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.anthropic.com'))
            ->last();
        $this->assertStringContainsString(trim((string) json_encode('екохутро'), '"'), $second[0]->body());
    }

    public function test_discards_reply_when_client_wrote_during_generation(): void
    {
        $this->setUpConversation();
        $convId = $this->conv->id;

        Http::fake([
            // Поки «Claude думає», клієнт дописує ще одне повідомлення
            'api.anthropic.com/*' => function () use ($convId) {
                InboxMessage::create([
                    'inbox_conversation_id' => $convId, 'direction' => 'in', 'sender' => 'contact',
                    'external_message_id' => 'm_in_late', 'text' => 'А, і ще розмір 38!', 'sent_at' => now(),
                ]);

                return Http::response([
                    'content' => [['type' => 'text', 'text' => 'Відповідь на половину питання']],
                    'usage' => ['input_tokens' => 50, 'output_tokens' => 10],
                ], 200);
            },
            'graph.facebook.com/*' => Http::response(['message_id' => 'm_x'], 200),
        ]);

        $this->runJob();

        $this->assertDatabaseHas('ai_runs', ['status' => 'skipped_stale_late']);
        $this->assertDatabaseMissing('inbox_messages', ['sender' => 'ai']);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'graph.facebook.com'));
    }

    public function test_claude_error_is_logged_not_sent(): void
    {
        $this->setUpConversation();
        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => ['message' => 'overloaded']], 529),
        ]);

        $this->runJob();

        $run = AiRun::where('status', 'error')->first();
        $this->assertNotNull($run);
        $this->assertStringContainsString('overloaded', (string) $run->error);
        $this->assertDatabaseMissing('inbox_messages', ['sender' => 'ai']);
    }
}
