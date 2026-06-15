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
        // Правило про подвійну розмірну сітку присутнє (формат через слеш)
        $this->assertStringContainsString(trim((string) json_encode('38/39?'), '"'), $body);
        // Каталог кешується (cache_control на блоці)
        $this->assertStringContainsString('cache_control', $body);
    }

    public function test_ai_context_reset_hides_old_history_from_model(): void
    {
        $this->setUpConversation();

        // Стара (засмічена) переписка
        InboxMessage::create([
            'inbox_conversation_id' => $this->conv->id, 'direction' => 'out', 'sender' => 'ai',
            'external_message_id' => 'm_old_ai', 'text' => 'СТАРА_РЕПЛІКА_ПРО_ЛІНІЙКУ', 'sent_at' => now(),
        ]);

        // Скидання памʼяті (як кнопкою в чаті)
        $this->conv->update(['ai_context_after_id' => $this->conv->messages()->max('id')]);

        // Нове вхідне після скидання
        $fresh = InboxMessage::create([
            'inbox_conversation_id' => $this->conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_fresh', 'text' => 'Хочу пушисті', 'sent_at' => now(),
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Покажу всі варіанти 🙂']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 100, 'output_tokens' => 10],
            ], 200),
            'graph.facebook.com/*' => Http::response(['message_id' => 'm_after_reset'], 200),
        ]);

        (new AiRespondToMessage($this->conv->id, $fresh->id))->handle(app(AiAgentService::class));

        $body = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.anthropic.com'))
            ->first()[0]->body();

        // Стара репліка в запит НЕ потрапила, нове питання — потрапило
        $this->assertStringNotContainsString('СТАРА_РЕПЛІКА_ПРО_ЛІНІЙКУ', $body);
        $this->assertStringContainsString(trim((string) json_encode('Хочу пушисті'), '"'), $body);
        $this->assertDatabaseHas('inbox_messages', ['text' => 'Покажу всі варіанти 🙂']);
    }

    public function test_client_photo_goes_to_model_as_image_block(): void
    {
        $this->setUpConversation();

        // Клієнт надсилає фото з питанням ціни
        $photoMsg = InboxMessage::create([
            'inbox_conversation_id' => $this->conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_img_1', 'text' => 'Яка ціна на такі?',
            'attachments' => [['type' => 'image', 'url' => 'https://scontent.test/client-photo.jpg']],
            'sent_at' => now(),
        ]);

        Http::fake([
            'scontent.test/*' => Http::response('FAKE_JPEG_BYTES', 200, ['Content-Type' => 'image/jpeg']),
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Це наші домашні пухнасті — 380 грн 🙂']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1500, 'output_tokens' => 30],
            ], 200),
            'graph.facebook.com/*' => Http::response(['message_id' => 'm_vision_1'], 200),
        ]);

        (new AiRespondToMessage($this->conv->id, $photoMsg->id))->handle(app(AiAgentService::class));

        $body = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.anthropic.com'))
            ->first()[0]->body();

        // Картинка пішла image-блоком (base64 від байтів фото), текст питання поруч
        $this->assertStringContainsString('"type":"image"', $body);
        $this->assertStringContainsString(base64_encode('FAKE_JPEG_BYTES'), $body);
        $this->assertStringContainsString(trim((string) json_encode('Яка ціна на такі?'), '"'), $body);
        $this->assertDatabaseHas('inbox_messages', ['text' => 'Це наші домашні пухнасті — 380 грн 🙂']);
    }

    public function test_screenshot_of_our_gallery_photo_is_matched_exactly(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD недоступний');
        }

        $this->setUpConversation();

        // Наше фото в галереї (реальний PNG на диску) з привʼязаним товаром
        $product = \App\Models\Product::create(['title' => 'Капці для вулиці КАПУЧИНО', 'sku' => '6033', 'sale_price' => 530, 'currency' => 'UAH', 'is_active' => true]);
        $group = \App\Models\AiPhotoGroup::create(['name' => 'Вуличні']);
        $group->products()->attach($product->id);

        $img = imagecreatetruecolor(40, 30);
        imagefilledrectangle($img, 0, 0, 39, 29, imagecolorallocate($img, 200, 150, 90));
        imagefilledellipse($img, 20, 15, 22, 14, imagecolorallocate($img, 80, 50, 20));
        $dir = public_path('ai-gallery');
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        imagepng($img, $dir . '/test-match.png');
        imagedestroy($img);

        $photo = \App\Models\AiPhoto::create(['ai_photo_group_id' => $group->id, 'path' => 'ai-gallery/test-match.png', 'sort_order' => 1]);
        $photo->products()->attach($product->id);

        // Клієнт «скидає скрін» цього ж фото
        $bytes = (string) file_get_contents($dir . '/test-match.png');
        $msg = InboxMessage::create([
            'inbox_conversation_id' => $this->conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_screen', 'text' => 'Ціна?',
            'attachments' => [['type' => 'image', 'url' => 'https://scontent.test/screen.png']],
            'sent_at' => now(),
        ]);

        Http::fake([
            'scontent.test/*' => Http::response($bytes, 200, ['Content-Type' => 'image/png']),
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Це вуличні капучино — 530 грн 🙂']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1500, 'output_tokens' => 30],
            ], 200),
            'graph.facebook.com/*' => Http::response(['message_id' => 'm_match_1'], 200),
        ]);

        (new AiRespondToMessage($this->conv->id, $msg->id))->handle(app(AiAgentService::class));

        $body = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.anthropic.com'))
            ->first()[0]->body();

        // Системна позначка про точний збіг пішла моделі разом із товаром і ціною
        $this->assertStringContainsString(trim((string) json_encode('це фото збігається з фото №'), '"'), $body);
        $this->assertStringContainsString(trim((string) json_encode('Капці для вулиці КАПУЧИНО'), '"'), $body);
        $this->assertStringContainsString('530', $body);

        @unlink($dir . '/test-match.png');
    }

    public function test_expired_client_photo_degrades_to_text_note(): void
    {
        $this->setUpConversation();

        $photoMsg = InboxMessage::create([
            'inbox_conversation_id' => $this->conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_img_dead', 'text' => '',
            'attachments' => [['type' => 'image', 'url' => 'https://scontent.test/expired.jpg']],
            'sent_at' => now(),
        ]);

        Http::fake([
            'scontent.test/*' => Http::response('gone', 403),
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Підкажіть, що саме цікавить? 🙂']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 200, 'output_tokens' => 15],
            ], 200),
            'graph.facebook.com/*' => Http::response(['message_id' => 'm_vision_2'], 200),
        ]);

        (new AiRespondToMessage($this->conv->id, $photoMsg->id))->handle(app(AiAgentService::class));

        $body = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.anthropic.com'))
            ->first()[0]->body();

        // Фото протухло → image-блоку нема, але модель знає, що клієнт щось надсилав
        $this->assertStringNotContainsString('"type":"image"', $body);
        $this->assertStringContainsString(trim((string) json_encode('клієнт надіслав фото'), '"'), $body);
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

    public function test_system_prompt_forbids_dodging_price_question(): void
    {
        $this->setUpConversation();
        $blocks = app(AiAgentService::class)->buildSystemPrompt($this->conv, AiSetting::forConnection($this->conn->id));
        $text = json_encode($blocks, JSON_UNESCAPED_UNICODE);

        // Правило: на питання про ціну — спершу цифра, не «будете замовляти?».
        $this->assertStringContainsString('ЗАБОРОНЕНО', $text);
        $this->assertStringContainsString('будете замовляти', $text);
        // Правило привітання на першу відповідь.
        $this->assertStringContainsString('привітання', $text);
        // Ввічливий заклик + форматування рядками.
        $this->assertStringContainsString('Бажаєте замовити', $text);
        $this->assertStringContainsString('ФОРМАТУВАННЯ', $text);
    }

    public function test_complete_order_sends_exact_final_message(): void
    {
        $this->setUpConversation();

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push(['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'complete_order', 'input' => [
                    'items' => [['title' => 'Домашні', 'color' => 'чорні', 'size' => '38', 'qty' => 1]],
                    'customer_name' => 'Іван', 'phone' => '0961234567', 'address' => 'Київ №5', 'payment' => 'при отриманні',
                ]]], 'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]], 200)
                ->push(['content' => [['type' => 'text', 'text' => 'вільний текст моделі']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 5, 'output_tokens' => 3]], 200),
            'graph.facebook.com/*' => Http::response(['message_id' => 'm_final'], 200),
        ]);

        $this->runJob();

        // Незалежно від тексту моделі — клієнту йде ТОЧНИЙ фінальний текст із конфігу.
        $final = AiAgentService::orderTexts()['final_message'];
        $this->assertDatabaseHas('inbox_messages', [
            'inbox_conversation_id' => $this->conv->id, 'sender' => 'ai', 'text' => $final,
        ]);
        $this->assertFalse((bool) $this->conv->fresh()->ai_enabled);
        $this->assertSame('Іван', $this->conv->fresh()->ai_order_customer_name);
    }

    public function test_voice_message_gets_polite_reply_and_skips_model(): void
    {
        $this->setUpConversation();

        $voice = InboxMessage::create([
            'inbox_conversation_id' => $this->conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_voice_1', 'text' => null,
            'attachments' => [['type' => 'audio', 'url' => 'https://cdn.meta/voice.mp4']],
            'sent_at' => now(),
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'НЕ МАЄ ВИКЛИКАТИСЯ']], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]], 200),
            'graph.facebook.com/*' => Http::response(['message_id' => 'm_vr_out'], 200),
        ]);

        (new AiRespondToMessage($this->conv->id, $voice->id))->handle(app(AiAgentService::class));

        // Бот відповів фіксованим текстом про голосові
        $this->assertDatabaseHas('inbox_messages', [
            'inbox_conversation_id' => $this->conv->id, 'direction' => 'out', 'sender' => 'ai',
            'text' => AiAgentService::orderTexts()['voice_reject'],
        ]);
        // Claude НЕ викликався
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'api.anthropic.com'));
        // Прогін позначено окремим статусом
        $this->assertDatabaseHas('ai_runs', [
            'inbox_conversation_id' => $this->conv->id, 'status' => 'replied_voice',
        ]);
    }

    public function test_voice_with_text_caption_still_goes_to_model(): void
    {
        $this->setUpConversation();

        $msg = InboxMessage::create([
            'inbox_conversation_id' => $this->conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_voice_2', 'text' => 'Скільки коштує?',
            'attachments' => [['type' => 'audio', 'url' => 'https://cdn.meta/voice2.mp4']],
            'sent_at' => now(),
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Вітаю! 530 грн.']], 'usage' => ['input_tokens' => 50, 'output_tokens' => 10]], 200),
            'graph.facebook.com/*' => Http::response(['message_id' => 'm_ok'], 200),
        ]);

        (new AiRespondToMessage($this->conv->id, $msg->id))->handle(app(AiAgentService::class));

        // Є текст → коротке замикання НЕ спрацьовує, модель викликана
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.anthropic.com'));
        $this->assertDatabaseMissing('inbox_messages', [
            'inbox_conversation_id' => $this->conv->id,
            'text' => AiAgentService::orderTexts()['voice_reject'],
        ]);
    }

    // --- Авто-статус «В роботі» для зацікавлених ---

    private function fakeEngagedTurn(): void
    {
        // Claude кличе ask_delivery_details (ознака зацікавленості), далі — текст.
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push(['content' => [['type' => 'tool_use', 'id' => 'tu_d', 'name' => 'ask_delivery_details', 'input' => []]], 'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]], 200)
                ->push(['content' => [['type' => 'text', 'text' => 'Чекаю ваші дані 🙂']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 12, 'output_tokens' => 6]], 200),
            'graph.facebook.com/*' => Http::sequence()
                ->push(['message_id' => 'm_tmpl'], 200)
                ->push(['message_id' => 'm_fin'], 200),
        ]);
    }

    public function test_engaged_client_moves_to_in_progress(): void
    {
        $this->setUpConversation(); // стартує у «Новий» (дефолт)
        $this->fakeEngagedTurn();

        $this->runJob();

        $inProgress = \App\Models\ChatStatus::where('code', 'in_progress')->firstOrFail();
        $this->assertSame($inProgress->id, $this->conv->fresh()->chat_status_id);
    }

    public function test_engagement_does_not_override_manual_status(): void
    {
        $this->setUpConversation();
        $closed = \App\Models\ChatStatus::where('code', 'closed')->firstOrFail();
        $this->conv->update(['chat_status_id' => $closed->id]);

        $this->fakeEngagedTurn();
        $this->runJob();

        // Ручний статус оператора не перетирається
        $this->assertSame($closed->id, $this->conv->fresh()->chat_status_id);
    }

    public function test_plain_reply_keeps_new_status(): void
    {
        $this->setUpConversation();
        $new = \App\Models\ChatStatus::where('code', 'new')->firstOrFail();

        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Вітаю!']], 'usage' => ['input_tokens' => 5, 'output_tokens' => 3]], 200),
            'graph.facebook.com/*' => Http::response(['message_id' => 'm1'], 200),
        ]);

        $this->runJob();

        // Просто відповів без зацікавленості → лишається «Новий»
        $this->assertSame($new->id, $this->conv->fresh()->chat_status_id);
    }
}
