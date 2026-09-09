<?php

namespace Tests\Feature;

use App\Models\TelegramSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456:abcdefghijklmnopqrstuvwxyz_123456789';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function owner(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner', 'is_active' => true]));
    }

    private function fakeTelegram(string $type = 'supergroup', string $member = 'member', int $sendStatus = 200): void
    {
        Http::fake([
            'api.telegram.org/bot*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 123456, 'username' => 'example_bot', 'first_name' => 'Помічник']]),
            'api.telegram.org/bot*/getChat' => Http::response(['ok' => true, 'result' => ['id' => -12345, 'title' => 'Робоча група', 'type' => $type, 'permissions' => ['can_send_messages' => true]]]),
            'api.telegram.org/bot*/getChatMember' => Http::response(['ok' => true, 'result' => ['status' => $member]]),
            'api.telegram.org/bot*/sendMessage' => Http::response($sendStatus === 200 ? ['ok' => true, 'result' => ['message_id' => 17]] : ['ok' => false, 'description' => self::TOKEN], $sendStatus),
        ]);
    }

    private function connect(): void
    {
        $this->postJson('/settings/telegram/connect', ['bot_token' => self::TOKEN, 'chat_id' => '-12345'])->assertOk();
    }

    private function preferences(bool $enabled = true, bool $test = true): array
    {
        return ['enabled' => $enabled, 'permissions' => ['manual_test' => $test, 'warehouse_reminder' => false, 'new_order' => false, 'return_alert' => false]];
    }

    public function test_connection_encrypts_token_and_never_sends_messages(): void
    {
        $this->owner();
        $this->fakeTelegram();
        $this->connect();
        $this->assertNotSame(self::TOKEN, DB::table('telegram_settings')->value('bot_token'));
        $this->assertSame(self::TOKEN, TelegramSetting::current()->bot_token);
        $this->getJson('/settings/telegram')->assertOk()->assertJson(['has_token' => true, 'enabled' => false, 'bot_username' => 'example_bot'])->assertJsonMissingPath('bot_token')->assertDontSee(self::TOKEN);
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/sendMessage'));
    }

    public function test_owner_page_has_mount_point_and_navigation_link(): void
    {
        $this->withoutVite();
        $this->owner();
        $this->get('/settings/telegram')->assertOk()->assertSee('crm-settings-telegram')->assertSee(route('settings.telegram.index'));
        Http::assertNothingSent();
    }

    public function test_validation_never_flashes_token_to_session(): void
    {
        $this->owner();
        $this->from('/settings/telegram')->post('/settings/telegram/connect', ['bot_token' => self::TOKEN, 'chat_id' => 'invalid'])->assertRedirect('/settings/telegram');
        $this->assertArrayNotHasKey('bot_token', session()->getOldInput());
        Http::assertNothingSent();
    }

    public function test_both_switches_gate_tests_and_settings_survive_reload(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->owner();
        $this->fakeTelegram();
        $this->connect();
        $this->postJson('/settings/telegram/test')->assertUnprocessable();
        $this->putJson('/settings/telegram', $this->preferences(true, false))->assertOk();
        $this->postJson('/settings/telegram/test')->assertUnprocessable();
        $this->putJson('/settings/telegram', $this->preferences(false, true))->assertOk();
        $this->postJson('/settings/telegram/test')->assertUnprocessable();
        Http::assertSentCount(3);
        $this->putJson('/settings/telegram', $this->preferences())->assertOk();
        $this->postJson('/settings/telegram/test')->assertOk();
        $this->getJson('/settings/telegram')->assertJson(['enabled' => true, 'permissions' => ['manual_test' => true]])->assertJsonPath('last_test_at', fn ($value) => is_string($value));
        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage') && $request['chat_id'] === '-12345');
        $this->assertFalse(TelegramSetting::current()->allows('unknown'));
    }

    public function test_cannot_enable_unverified_connection_or_save_unknown_permissions(): void
    {
        $this->owner();
        $this->putJson('/settings/telegram', $this->preferences())->assertUnprocessable();
        $data = $this->preferences(false);
        $data['permissions']['anything'] = true;
        $this->putJson('/settings/telegram', $data)->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_private_chats_are_rejected(): void
    {
        $this->owner();
        $this->fakeTelegram('private');
        $this->postJson('/settings/telegram/connect', ['bot_token' => self::TOKEN, 'chat_id' => '-12345'])->assertUnprocessable();
        $this->assertDatabaseCount('telegram_settings', 0);
    }

    public function test_removed_bots_are_rejected(): void
    {
        $this->owner(); $this->fakeTelegram('group', 'left');
        $this->postJson('/settings/telegram/connect', ['bot_token' => self::TOKEN, 'chat_id' => '-12345'])->assertUnprocessable();
        $this->assertDatabaseCount('telegram_settings', 0);
    }

    public function test_replacing_credentials_pauses_sending_and_empty_token_keeps_secret(): void
    {
        $this->owner(); $this->fakeTelegram(); $this->connect();
        $this->putJson('/settings/telegram', $this->preferences())->assertOk();
        $this->postJson('/settings/telegram/connect', ['bot_token' => '', 'chat_id' => '-12345'])->assertOk()->assertJson(['enabled' => true]);
        $this->assertSame(self::TOKEN, TelegramSetting::current()->bot_token);
        $this->postJson('/settings/telegram/connect', ['bot_token' => self::TOKEN.'x', 'chat_id' => '-12345'])->assertOk()->assertJson(['enabled' => false, 'last_test_at' => null]);
    }

    public function test_telegram_failures_do_not_expose_secrets_or_retry_sending(): void
    {
        $this->owner(); $this->fakeTelegram(sendStatus: 403); $this->connect();
        $this->putJson('/settings/telegram', $this->preferences())->assertOk();
        $this->postJson('/settings/telegram/test')->assertUnprocessable()->assertDontSee(self::TOKEN);
        $this->assertNull(TelegramSetting::current()->last_test_at);
        Http::assertSentCount(4);
    }

    public function test_only_active_owner_can_manage_telegram(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'operator', 'is_active' => true]));
        $this->getJson('/settings/telegram')->assertForbidden();
        $this->postJson('/settings/telegram/connect', [])->assertForbidden();
        $this->putJson('/settings/telegram', [])->assertForbidden();
        $this->postJson('/settings/telegram/test')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_manual_tests_have_a_separate_rate_limit(): void
    {
        $this->owner(); $this->fakeTelegram(); $this->connect();
        $this->putJson('/settings/telegram', $this->preferences())->assertOk();
        $this->postJson('/settings/telegram/test')->assertOk();
        $this->postJson('/settings/telegram/test')->assertOk();
        $this->postJson('/settings/telegram/test')->assertStatus(429);
        Http::assertSentCount(5);
    }
}
