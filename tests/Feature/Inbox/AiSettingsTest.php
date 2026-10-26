<?php

namespace Tests\Feature\Inbox;

use App\Models\AiSetting;
use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\MetaConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create(['role' => User::ROLE_OWNER, 'is_active' => true]);
    }

    private function connection(): MetaConnection
    {
        return MetaConnection::create([
            'page_id' => 'PAGE1', 'page_name' => 'Test Page', 'page_access_token' => 'tok', 'status' => 'active',
        ]);
    }

    public function test_owner_saves_settings_and_key_is_encrypted(): void
    {
        $conn = $this->connection();

        $this->actingAs($this->owner())->postJson('/settings/ai/save', [
            'api_key' => 'sk-ant-test-12345',
            'model' => 'claude-sonnet-4-6',
            'stores' => [[
                'meta_connection_id' => $conn->id,
                'enabled' => true,
                'system_prompt' => 'Ти продавець.',
            ]],
        ])->assertOk()->assertJson(['ok' => true]);

        $global = AiSetting::whereNull('meta_connection_id')->first();
        $this->assertSame('sk-ant-test-12345', $global->api_key);
        $this->assertSame('claude-sonnet-4-6', $global->model);

        // В базі ключ зашифрований, не plaintext
        $raw = DB::table('ai_settings')->whereNull('meta_connection_id')->value('api_key');
        $this->assertNotSame('sk-ant-test-12345', $raw);

        $store = AiSetting::where('meta_connection_id', $conn->id)->first();
        $this->assertTrue($store->enabled);
        $this->assertSame('Ти продавець.', $store->system_prompt);
    }

    public function test_saving_without_key_keeps_existing_key(): void
    {
        AiSetting::global()->update(['api_key' => 'sk-ant-old']);

        $this->actingAs($this->owner())->postJson('/settings/ai/save', [
            'api_key' => null,
            'model' => 'claude-haiku-4-5-20251001',
            'stores' => [],
        ])->assertOk();

        $this->assertSame('sk-ant-old', AiSetting::global()->api_key);
    }

    public function test_key_check_calls_anthropic(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['data' => []], 200)]);

        $this->actingAs($this->owner())->postJson('/settings/ai/test', ['api_key' => 'sk-ant-x'])
            ->assertOk()->assertJson(['ok' => true]);
    }

    public function test_operator_cannot_open_ai_settings(): void
    {
        $operator = User::factory()->create(['role' => User::ROLE_OPERATOR, 'is_active' => true]);

        $this->actingAs($operator)->get('/settings/ai')->assertForbidden();
    }

    public function test_toggle_ai_for_conversation(): void
    {
        $conn = $this->connection();
        $contact = InboxContact::create([
            'meta_connection_id' => $conn->id, 'channel' => 'facebook', 'external_id' => 'U1',
        ]);
        $conv = InboxConversation::create([
            'meta_connection_id' => $conn->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook',
        ]);

        $operator = User::factory()->create(['role' => User::ROLE_OPERATOR, 'is_active' => true]);

        $this->actingAs($operator)
            ->postJson("/api/inbox/conversations/{$conv->id}/ai", ['enabled' => false])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertFalse($conv->fresh()->ai_enabled);

        $this->actingAs($operator)
            ->postJson("/api/inbox/conversations/{$conv->id}/ai", ['enabled' => true])
            ->assertOk();

        $this->assertTrue($conv->fresh()->ai_enabled);
    }

    public function test_openai_credentials_are_separate_encrypted_and_do_not_enable_openai(): void
    {
        AiSetting::global()->update(['api_key' => 'sk-ant-existing', 'model' => 'claude-sonnet-4-6']);
        $this->actingAs($this->owner())->postJson('/settings/ai/save', [
            'model' => 'claude-sonnet-4-6', 'openai_api_key' => 'sk-openai-private', 'openai_model' => 'gpt-6.1-sol',
        ])->assertOk();
        $global = AiSetting::global();
        $this->assertNull($global->provider);
        $this->assertSame('sk-ant-existing', $global->api_key);
        $this->assertSame('sk-openai-private', $global->openai_api_key);
        $this->assertNotSame('sk-openai-private', DB::table('ai_settings')->where('id', $global->id)->value('openai_api_key'));
        $this->assertArrayNotHasKey('openai_api_key', $global->toArray());
        $response = $this->getJson('/settings/ai/data')->assertOk()->assertJsonPath('global.provider', 'claude')->assertJsonPath('global.openai_has_key', true);
        $this->assertStringNotContainsString('sk-openai-private', $response->getContent());
        $this->assertStringNotContainsString('sk-ant-existing', $response->getContent());
    }

    public function test_cannot_switch_to_openai_without_its_key_and_does_not_partially_save(): void
    {
        $global = AiSetting::global();
        $global->update(['api_key' => 'sk-ant-existing', 'model' => 'claude-sonnet-4-6']);
        $connection = $this->connection();
        $store = AiSetting::forConnection($connection->id);
        $store->update(['enabled' => true, 'system_prompt' => 'Попередня інструкція']);

        $this->actingAs($this->owner())->postJson('/settings/ai/save', [
            'provider' => 'openai', 'model' => 'claude-new',
            'stores' => [['meta_connection_id' => $connection->id, 'enabled' => false, 'system_prompt' => 'Нова інструкція']],
        ])->assertUnprocessable()->assertJsonValidationErrors('openai_api_key');
        $this->assertNull($global->fresh()->provider);
        $this->assertSame('claude-sonnet-4-6', $global->fresh()->model);
        $this->assertTrue($store->fresh()->enabled);
        $this->assertSame('Попередня інструкція', $store->fresh()->system_prompt);
    }

    public function test_explicit_global_switch_preserves_credentials_for_both_apis(): void
    {
        AiSetting::global()->update(['api_key' => 'sk-ant-existing', 'openai_api_key' => 'sk-openai-existing']);
        $this->actingAs($this->owner());
        $this->postJson('/settings/ai/save', ['provider' => 'openai', 'model' => 'claude-sonnet-4-6'])->assertOk();
        $this->assertSame('openai', AiSetting::global()->provider);
        $this->postJson('/settings/ai/save', ['provider' => 'claude', 'model' => 'claude-sonnet-4-6'])->assertOk();
        $this->assertSame('claude', AiSetting::global()->provider);
        $this->assertSame('sk-ant-existing', AiSetting::global()->api_key);
        $this->assertSame('sk-openai-existing', AiSetting::global()->openai_api_key);
    }

    public function test_cannot_switch_to_claude_without_its_key(): void
    {
        AiSetting::global()->update(['provider' => 'openai', 'openai_api_key' => 'sk-openai-existing']);
        $this->actingAs($this->owner())->postJson('/settings/ai/save', ['provider' => 'claude', 'model' => 'claude-sonnet-4-6'])
            ->assertUnprocessable()->assertJsonValidationErrors('api_key');
        $this->assertSame('openai', AiSetting::global()->provider);
    }

    public function test_legacy_save_preserves_selected_provider_and_both_keys(): void
    {
        AiSetting::global()->update(['provider' => 'openai', 'api_key' => 'sk-ant-existing', 'openai_api_key' => 'sk-openai-existing', 'openai_model' => 'gpt-6.1-sol']);
        $connection = $this->connection();
        AiSetting::forConnection($connection->id)->update(['enabled' => true, 'provider' => 'claude']);
        $this->actingAs($this->owner())->postJson('/settings/ai/save', [
            'model' => 'claude-sonnet-4-6', 'api_key' => null, 'openai_api_key' => null,
            'stores' => [['meta_connection_id' => $connection->id, 'enabled' => true]],
        ])->assertOk();
        $global = AiSetting::global();
        $this->assertSame('openai', $global->provider);
        $this->assertSame('sk-ant-existing', $global->api_key);
        $this->assertSame('sk-openai-existing', $global->openai_api_key);
        $this->assertSame('gpt-6.1-sol', $global->openai_model);
        $this->assertSame('claude', AiSetting::forConnection($connection->id)->provider);
    }

    public function test_openai_key_check_uses_draft_key_and_model_without_switching_provider(): void
    {
        AiSetting::global()->update(['api_key' => 'sk-ant-existing']);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/v1/models/gpt-6.1-sol' => Http::response(['id' => 'gpt-6.1-sol'])]);
        $this->actingAs($this->owner())->postJson('/settings/ai/test', ['provider' => 'openai', 'api_key' => 'sk-openai-draft', 'model' => 'gpt-6.1-sol'])->assertOk();
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->hasHeader('Authorization', 'Bearer sk-openai-draft'));
        $this->assertNull(AiSetting::global()->provider);
        $this->assertNull(AiSetting::global()->openai_api_key);
    }

    public function test_operator_cannot_change_or_inspect_api_credentials(): void
    {
        $operator = User::factory()->create(['role' => User::ROLE_OPERATOR, 'is_active' => true]);
        Http::fake();
        $this->actingAs($operator)->getJson('/settings/ai/data')->assertForbidden();
        $this->postJson('/settings/ai/save', ['provider' => 'openai', 'model' => 'claude-sonnet-4-6'])->assertForbidden();
        $this->postJson('/settings/ai/test', ['provider' => 'openai', 'api_key' => 'sk-test'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_owner_page_renders_one_global_switch_without_sandbox(): void
    {
        $this->actingAs($this->owner())->get('/settings/ai')->assertOk()
            ->assertSee('Перемикач API')->assertSee('Підключення Claude API')->assertSee('Підключення ChatGPT API (OpenAI)')->assertDontSee('Спробувати OpenAI');
    }
}
