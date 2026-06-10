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
}
