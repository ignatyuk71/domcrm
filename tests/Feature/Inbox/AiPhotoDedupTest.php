<?php

namespace Tests\Feature\Inbox;

use App\Models\AiPhoto;
use App\Models\AiPhotoGroup;
use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
use App\Models\Product;
use App\Services\Ai\AiAgentService;
use App\Services\Ai\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Сторож проти дубля «текст до фото + переказ після фото» (кейс Валентини):
 * текст усіх кроків склеюється в одне повідомлення, тому модель має знати
 * про склейку (правило в промпті) і отримувати нагадування після send_photos.
 */
class AiPhotoDedupTest extends TestCase
{
    use RefreshDatabase;

    private function makeConversation(): array
    {
        $conn = MetaConnection::create(['page_id' => 'P_DD', 'page_name' => 'Shop', 'page_access_token' => 'tok', 'status' => 'active']);
        \App\Models\AiSetting::global()->update(['api_key' => 'sk-ant-test', 'model' => 'claude-sonnet-4-6']);
        \App\Models\AiSetting::forConnection($conn->id)->update(['enabled' => true, 'system_prompt' => 'Ти продавець.']);

        $contact = InboxContact::create(['meta_connection_id' => $conn->id, 'channel' => 'facebook', 'external_id' => 'U_DD']);
        $conv = InboxConversation::create(['meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook']);

        return [$conn, $conv];
    }

    private function makeCollage(): AiPhoto
    {
        $product = Product::create(['title' => 'Вуличні тапки чорні', 'sku' => 'DD1', 'sale_price' => 530, 'currency' => 'UAH', 'is_active' => true]);
        $group = AiPhotoGroup::create(['name' => 'Вуличні']);
        $group->products()->attach([$product->id]);
        $collage = AiPhoto::create(['ai_photo_group_id' => $group->id, 'path' => 'ai-gallery/dd.jpg', 'sort_order' => 1]);
        $collage->products()->attach([$product->id]);

        return $collage;
    }

    public function test_prompt_contains_glue_rule(): void
    {
        [, $conv] = $this->makeConversation();

        $system = collect(app(PromptBuilder::class)->buildSystemPrompt($conv))
            ->pluck('text')->implode("\n");

        $this->assertStringContainsString('СКЛЕЙКА ТЕКСТУ (ОБОВ\'ЯЗКОВЕ ПРАВИЛО)', $system);
        $this->assertStringContainsString('склеюються в ОДНЕ повідомлення', $system);
        $this->assertStringContainsString('Ось як вони виглядають', $system, 'приклад забороненої підводки мусить бути в правилі');
        $this->assertStringContainsString('ОДНИМ блоком у тій самій відповіді, де викликаєш send_photos', $system);
    }

    public function test_photos_sent_note_is_injected_after_send_photos(): void
    {
        [, $conv] = $this->makeConversation();
        $collage = $this->makeCollage();

        $incoming = InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_dd1', 'text' => 'Покажіть вуличні', 'sent_at' => now(),
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'content' => [
                        ['type' => 'text', 'text' => 'Вуличні капці — 530 грн 🙂'],
                        ['type' => 'tool_use', 'id' => 'tu_dd', 'name' => 'send_photos', 'input' => ['photo_ids' => [$collage->id]]],
                    ],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 300, 'output_tokens' => 30],
                ], 200)
                ->push([
                    'content' => [['type' => 'text', 'text' => 'Який колір показати ближче?']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 350, 'output_tokens' => 25],
                ], 200),
            'graph.facebook.com/*' => Http::sequence()
                ->push(['message_id' => 'm_dd_photo'], 200)
                ->push(['message_id' => 'm_dd_text'], 200),
        ]);

        (new \App\Jobs\AiRespondToMessage($conv->id, $incoming->id))->handle(app(AiAgentService::class));

        // Другий запит до Anthropic мусить нести нагадування про склейку.
        $second = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->first(function ($req) {
                if (!str_contains($req->url(), 'api.anthropic.com')) {
                    return false;
                }
                return str_contains(json_encode($req->data(), JSON_UNESCAPED_UNICODE), 'tool_result');
            });

        $this->assertNotNull($second, 'другий запит із tool_result не знайдено');
        $body = json_encode($second->data(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('фото вже надіслані клієнту', $body);
        $this->assertStringContainsString('НЕ повторюй і НЕ перефразовуй', $body);

        // І кінцевий текст клієнту — обидва шматки, як і раніше (механіку не зламали).
        $this->assertDatabaseHas('inbox_messages', [
            'sender' => 'ai',
            'text' => "Вуличні капці — 530 грн 🙂\n\nЯкий колір показати ближче?",
        ]);
    }
}