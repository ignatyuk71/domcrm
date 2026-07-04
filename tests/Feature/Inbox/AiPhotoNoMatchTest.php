<?php

namespace Tests\Feature\Inbox;

use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
use App\Services\Ai\AiAgentService;
use App\Services\Ai\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Сторож проти вгадування кольору з фото клієнта (кейс Ірини: скріншот
 * нашого поста з капучино → бот упевнено назвав «пудровий» і надіслав
 * фото не того кольору). Без точного збігу з галереєю: колір не називаємо,
 * даємо тип + ціну групи + колаж + питання.
 */
class AiPhotoNoMatchTest extends TestCase
{
    use RefreshDatabase;

    private function makeConversation(): InboxConversation
    {
        $conn = MetaConnection::create(['page_id' => 'P_NM', 'page_name' => 'Shop', 'page_access_token' => 'tok', 'status' => 'active']);
        \App\Models\AiSetting::global()->update(['api_key' => 'sk-ant-test', 'model' => 'claude-sonnet-4-6']);
        \App\Models\AiSetting::forConnection($conn->id)->update(['enabled' => true, 'system_prompt' => 'Ти продавець.']);

        $contact = InboxContact::create(['meta_connection_id' => $conn->id, 'channel' => 'instagram', 'external_id' => 'U_NM']);

        return InboxConversation::create(['meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'instagram']);
    }

    public function test_prompt_forbids_color_guessing_without_exact_match(): void
    {
        $conv = $this->makeConversation();

        $system = collect(app(PromptBuilder::class)->buildSystemPrompt($conv))
            ->pluck('text')->implode("\n");

        $this->assertStringContainsString('БЕЗ ТОЧНОГО ЗБІГУ — КОЛІР НЕ ВГАДУЙ (ОБОВ\'ЯЗКОВЕ ПРАВИЛО)', $system);
        $this->assertStringContainsString('НЕ надсилай фото «вгаданого» кольору', $system);
        $this->assertStringContainsString('Який саме колір вас цікавить', $system);
    }

    public function test_no_match_note_is_injected_for_client_photo(): void
    {
        $conv = $this->makeConversation();

        // Фото клієнта, якого нема в нашій галереї (галерея взагалі порожня).
        $incoming = InboxMessage::create([
            'inbox_conversation_id' => $conv->id, 'direction' => 'in', 'sender' => 'contact',
            'external_message_id' => 'm_nm1', 'text' => 'Ціна будь ласка?',
            'attachments' => [['type' => 'image', 'url' => 'https://cdn.meta/client-shot.jpg']],
            'sent_at' => now(),
        ]);

        // Валідний мінімальний JPEG, щоб vision-гілка його прийняла.
        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==');

        Http::fake([
            'cdn.meta/*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Вуличні капці — 530 грн 🙂 Який саме колір вас цікавить?']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 300, 'output_tokens' => 25],
            ], 200),
            'graph.facebook.com/*' => Http::response(['message_id' => 'm_nm_out'], 200),
        ]);

        (new \App\Jobs\AiRespondToMessage($conv->id, $incoming->id))->handle(app(AiAgentService::class));

        $request = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->first(fn ($req) => str_contains($req->url(), 'api.anthropic.com'));

        $this->assertNotNull($request, 'запит до Anthropic не знайдено');
        $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('точного збігу цього фото з нашою галереєю НЕМАЄ', $body);
        $this->assertStringContainsString('КОЛІР із фото НЕ визначай', $body);
    }
}