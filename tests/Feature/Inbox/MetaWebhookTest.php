<?php

namespace Tests\Feature\Inbox;

use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Без реальних HTTP (підтягування імені контакта). Секрет заданий — події підписуємо.
        \Illuminate\Support\Facades\Http::fake();
        config(['services.meta.app_secret' => 'test-secret']);
    }

    /** POST вебхука з валідним HMAC-підписом (бо тепер fail-closed). */
    private function postWebhook(array $payload)
    {
        $body = json_encode($payload);
        $sig = 'sha256=' . hash_hmac('sha256', $body, (string) config('services.meta.app_secret'));

        return $this->call('POST', '/api/meta/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $sig,
        ], $body);
    }

    private function connection(): MetaConnection
    {
        return MetaConnection::create([
            'page_id' => 'PAGE1',
            'page_name' => 'Test Page',
            'page_access_token' => 'token',
            'ig_account_id' => 'IG1',
            'status' => 'active',
        ]);
    }

    private function fbPayload(string $mid, string $text): array
    {
        return [
            'object' => 'page',
            'entry' => [[
                'id' => 'PAGE1',
                'messaging' => [[
                    'sender' => ['id' => 'USER1'],
                    'recipient' => ['id' => 'PAGE1'],
                    'timestamp' => 1700000000000,
                    'message' => ['mid' => $mid, 'text' => $text],
                ]],
            ]],
        ];
    }

    public function test_get_verification_returns_challenge_with_correct_token(): void
    {
        config(['services.meta.verify_token' => 'secret123']);

        $this->get('/api/meta/webhook?hub.mode=subscribe&hub.verify_token=secret123&hub.challenge=42')
            ->assertOk()
            ->assertSee('42');
    }

    public function test_get_verification_rejects_wrong_token(): void
    {
        config(['services.meta.verify_token' => 'secret123']);

        $this->get('/api/meta/webhook?hub.mode=subscribe&hub.verify_token=WRONG&hub.challenge=42')
            ->assertForbidden();
    }

    public function test_incoming_facebook_message_is_stored(): void
    {
        $conn = $this->connection();

        $this->postWebhook($this->fbPayload('m_1', 'Привіт'))->assertOk();

        $this->assertDatabaseHas('inbox_contacts', [
            'meta_connection_id' => $conn->id,
            'channel' => 'facebook',
            'external_id' => 'USER1',
        ]);
        $this->assertDatabaseHas('inbox_messages', [
            'external_message_id' => 'm_1',
            'direction' => 'in',
            'text' => 'Привіт',
        ]);

        $conversation = InboxConversation::first();
        $this->assertSame(1, (int) $conversation->unread_count);
        $this->assertSame('Привіт', $conversation->last_message_text);
    }

    public function test_echo_from_facebook_inbox_is_stored_as_outgoing(): void
    {
        $conn = $this->connection();

        // Працівник відповів клієнту прямо у ФБ — приходить echo: sender = сторінка, recipient = клієнт.
        $payload = [
            'object' => 'page',
            'entry' => [[
                'id' => 'PAGE1',
                'messaging' => [[
                    'sender' => ['id' => 'PAGE1'],
                    'recipient' => ['id' => 'USER1'],
                    'timestamp' => 1700000000000,
                    'message' => ['mid' => 'm_echo_1', 'text' => 'Добрий день!', 'is_echo' => true],
                ]],
            ]],
        ];

        $this->postWebhook($payload)->assertOk();

        // Контакт — клієнт (USER1), а не сторінка.
        $this->assertDatabaseHas('inbox_contacts', [
            'meta_connection_id' => $conn->id,
            'channel' => 'facebook',
            'external_id' => 'USER1',
        ]);
        $this->assertDatabaseHas('inbox_messages', [
            'external_message_id' => 'm_echo_1',
            'direction' => 'out',
            'sender' => 'agent',
            'text' => 'Добрий день!',
        ]);

        // Вихідне не збільшує лічильник непрочитаних.
        $this->assertSame(0, (int) InboxConversation::first()->unread_count);
    }

    public function test_instagram_message_is_stored_on_linked_account(): void
    {
        $this->connection();

        $payload = [
            'object' => 'instagram',
            'entry' => [[
                'id' => 'IG1',
                'messaging' => [[
                    'sender' => ['id' => 'IGUSER'],
                    'recipient' => ['id' => 'IG1'],
                    'timestamp' => 1700000000000,
                    'message' => ['mid' => 'ig_1', 'text' => 'Хочу замовити'],
                ]],
            ]],
        ];

        $this->postWebhook($payload)->assertOk();

        $this->assertDatabaseHas('inbox_contacts', ['channel' => 'instagram', 'external_id' => 'IGUSER']);
        $this->assertDatabaseHas('inbox_messages', ['external_message_id' => 'ig_1', 'text' => 'Хочу замовити']);
    }

    public function test_duplicate_message_is_ignored(): void
    {
        $this->connection();

        $this->postWebhook($this->fbPayload('m_dup', 'Hi'))->assertOk();
        $this->postWebhook($this->fbPayload('m_dup', 'Hi'))->assertOk();

        $this->assertSame(1, InboxMessage::where('external_message_id', 'm_dup')->count());
    }

    public function test_rejects_invalid_signature_when_secret_is_set(): void
    {
        config(['services.meta.app_secret' => 'sek']);
        $this->connection();

        $this->postJson('/api/meta/webhook', $this->fbPayload('sig_bad', 'фейк'), [
            'X-Hub-Signature-256' => 'sha256=deadbeef',
        ])->assertOk();

        $this->assertDatabaseMissing('inbox_messages', ['external_message_id' => 'sig_bad']);
    }

    public function test_rejects_event_when_app_secret_empty(): void
    {
        config(['services.meta.app_secret' => '']); // місконфіг — fail-closed
        $this->connection();

        // Порожній секрет → подію НЕ обробляємо (раніше тут був fail-open).
        $this->postJson('/api/meta/webhook', $this->fbPayload('no_secret', 'фейк'))->assertOk();

        $this->assertDatabaseMissing('inbox_messages', ['external_message_id' => 'no_secret']);
    }

    public function test_accepts_valid_signature_when_secret_is_set(): void
    {
        config(['services.meta.app_secret' => 'sek']);
        $this->connection();

        $payload = $this->fbPayload('sig_ok', 'справжнє');
        $body = json_encode($payload);
        $sig = 'sha256=' . hash_hmac('sha256', $body, 'sek');

        $this->call('POST', '/api/meta/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $sig,
        ], $body)->assertOk();

        $this->assertDatabaseHas('inbox_messages', ['external_message_id' => 'sig_ok']);
    }

    public function test_quote_reply_context_is_stored(): void
    {
        $this->connection();

        $payload = $this->fbPayload('m_ctx_1', 'А ці є в 38?');
        $payload['entry'][0]['messaging'][0]['message']['reply_to'] = ['mid' => 'm_orig_99'];

        $this->postWebhook($payload)->assertOk();

        $m = InboxMessage::where('external_message_id', 'm_ctx_1')->first();
        $this->assertSame(['type' => 'reply', 'mid' => 'm_orig_99'], $m->context);
    }

    public function test_story_reply_downloads_media_and_stores_context(): void
    {
        // Скидаємо blanket-fake з setUp: перший зареєстрований стаб виграє.
        \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory());
        \Illuminate\Support\Facades\Http::fake([
            'cdn.test/*' => \Illuminate\Support\Facades\Http::response('STORY_JPEG_BYTES', 200, ['Content-Type' => 'image/jpeg']),
            '*' => \Illuminate\Support\Facades\Http::response([], 200),
        ]);
        $this->connection();

        $payload = $this->fbPayload('m_ctx_2', 'Можна замовити?');
        $payload['entry'][0]['messaging'][0]['message']['reply_to'] = [
            'story' => ['url' => 'https://cdn.test/story123.jpg', 'id' => 'story123'],
        ];

        $this->postWebhook($payload)->assertOk();

        $m = InboxMessage::where('external_message_id', 'm_ctx_2')->first();
        $this->assertSame('story', $m->context['type']);
        $this->assertSame('https://cdn.test/story123.jpg', $m->context['url']);
        $this->assertNotEmpty($m->context['local']);
        $local = public_path($m->context['local']);
        $this->assertFileExists($local);
        $this->assertSame('STORY_JPEG_BYTES', file_get_contents($local));
        @unlink($local);
    }

    public function test_share_attachment_becomes_context(): void
    {
        \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory());
        \Illuminate\Support\Facades\Http::fake([
            'cdn.test/*' => \Illuminate\Support\Facades\Http::response('POST_IMG', 200, ['Content-Type' => 'image/png']),
            '*' => \Illuminate\Support\Facades\Http::response([], 200),
        ]);
        $this->connection();

        $payload = $this->fbPayload('m_ctx_3', '');
        unset($payload['entry'][0]['messaging'][0]['message']['text']);
        $payload['entry'][0]['messaging'][0]['message']['attachments'] = [
            ['type' => 'share', 'payload' => ['url' => 'https://cdn.test/post-image.png']],
        ];

        $this->postWebhook($payload)->assertOk();

        $m = InboxMessage::where('external_message_id', 'm_ctx_3')->first();
        $this->assertSame('share', $m->context['type']);
        $this->assertNotEmpty($m->context['local']);
        @unlink(public_path($m->context['local']));
    }
}
