<?php

namespace Tests\Feature\GptSandbox;

use App\Models\AiSetting;
use App\Models\User;
use App\Modules\GptSandbox\Models\SandboxChat;
use App\Modules\GptSandbox\Models\SandboxSetting;
use App\Modules\GptSandbox\Models\SandboxTurn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class GptSandboxTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/settings/gpt-sandbox';

    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\AiChannelSetting::current()->update(['gpt_enabled' => true]);
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => User::ROLE_OWNER, 'is_active' => true]);
    }

    private function configured(User $owner): SandboxChat
    {
        SandboxSetting::create(['user_id' => $owner->id, 'api_key' => 'sk-test-private-key', 'model' => 'gpt-test']);

        return SandboxChat::create(['user_id' => $owner->id]);
    }

    private function answer(string $text = 'Вітаю! Чим допомогти?', array $extra = []): array
    {
        return ['status' => 'completed', 'output' => [...$extra, [
            'type' => 'message', 'id' => 'msg_test', 'role' => 'assistant', 'status' => 'completed',
            'phase' => 'final_answer', 'content' => [['type' => 'output_text', 'text' => $text]],
        ]]];
    }

    public function test_page_and_every_endpoint_require_an_active_owner(): void
    {
        $owner = $this->owner();
        $chat = $this->configured($owner);
        $turn = $chat->turns()->create(['request_id' => Str::uuid(), 'text' => 'Привіт', 'model' => 'gpt-test']);

        foreach ([User::ROLE_OPERATOR, User::ROLE_PACKER] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'is_active' => true]));
            $this->get(self::BASE)->assertForbidden();
            $this->getJson(self::BASE.'/state')->assertForbidden();
            $this->putJson(self::BASE.'/settings', [])->assertForbidden();
            $this->postJson(self::BASE.'/connection', [])->assertForbidden();
            $this->postJson(self::BASE.'/chats')->assertForbidden();
            $this->postJson(self::BASE.'/chats/'.$chat->id.'/messages', [])->assertForbidden();
            $this->postJson(self::BASE.'/chats/'.$chat->id.'/turns/'.$turn->id.'/retry')->assertForbidden();
            $this->get(self::BASE.'/turns/'.$turn->id.'/images/0')->assertForbidden();
        }

        $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'is_active' => false]));
        $this->getJson(self::BASE.'/state')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_page_opens_without_contacting_any_api_or_creating_settings(): void
    {
        $this->withoutVite();
        $this->actingAs($this->owner())->get(self::BASE)->assertOk()->assertSee('gpt-sandbox-root', false);
        $this->getJson(self::BASE.'/state')->assertOk()->assertJsonPath('settings.has_key', false)->assertJsonPath('chat', null);
        $this->assertDatabaseCount('gpt_sandbox_settings', 0);
        Http::assertNothingSent();
    }

    public function test_credentials_are_encrypted_private_and_do_not_change_claude(): void
    {
        $owner = $this->owner();
        AiSetting::global()->update(['api_key' => 'sk-ant-untouched', 'model' => 'claude-existing']);
        $before = DB::table('ai_settings')->first();
        $this->actingAs($owner)->putJson(self::BASE.'/settings', ['api_key' => 'sk-test-secret', 'model' => 'gpt-test'])
            ->assertOk()->assertJsonPath('settings.has_key', true)->assertDontSee('sk-test-secret');
        $this->assertNotSame('sk-test-secret', DB::table('gpt_sandbox_settings')->value('api_key'));
        $this->assertSame('sk-test-secret', SandboxSetting::first()->api_key);
        $this->putJson(self::BASE.'/settings', ['api_key' => null, 'model' => 'gpt-next'])->assertOk();
        $this->assertSame('sk-test-secret', SandboxSetting::first()->api_key);
        $this->getJson(self::BASE.'/state')->assertDontSee('sk-test-secret')->assertJsonPath('settings.model', 'gpt-next');
        $this->assertEquals($before, DB::table('ai_settings')->first());
        $this->actingAs($this->owner())->getJson(self::BASE.'/state')->assertJsonPath('settings.has_key', false);
        Http::assertNothingSent();
    }

    public function test_invalid_settings_do_not_flash_api_key(): void
    {
        $this->actingAs($this->owner())->put(self::BASE.'/settings', ['api_key' => 'sk-secret', 'model' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('model')->assertSessionMissing('_old_input');
        $this->assertDatabaseCount('gpt_sandbox_settings', 0);
    }

    public function test_connection_uses_only_openai_and_does_not_save_an_unverified_draft(): void
    {
        Http::fake(['api.openai.com/v1/models' => Http::response(['data' => [
            ['id' => 'gpt-test'], ['id' => 'gpt-test'], ['id' => 'gpt-audio'], ['id' => 'text-embedding-3-small'],
        ]])]);
        $this->actingAs($this->owner())->postJson(self::BASE.'/connection', ['api_key' => 'sk-draft'])
            ->assertOk()->assertJsonPath('models', ['gpt-test'])->assertJsonPath('settings.connection_status', 'missing');
        $this->assertDatabaseCount('gpt_sandbox_settings', 0);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-draft'));
    }

    public function test_text_image_and_full_response_history_are_sent_to_responses_api(): void
    {
        $owner = $this->owner();
        $chat = $this->configured($owner);
        $reasoning = ['id' => 'rs_test', 'type' => 'reasoning', 'encrypted_content' => 'opaque-reasoning', 'summary' => []];
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->answer('Бачу фото.', [$reasoning]))->push($this->answer('Так, пам’ятаю фото.'))]);
        $this->actingAs($owner)->post(self::BASE.'/chats/'.$chat->id.'/messages', [
            'request_id' => (string) Str::uuid(), 'text' => 'Що на фото?',
            'images' => [UploadedFile::fake()->image('photo.png', 40, 40)],
        ])->assertOk()->assertJsonPath('turn.reply', 'Бачу фото.');
        $this->postJson(self::BASE.'/chats/'.$chat->id.'/messages', [
            'request_id' => (string) Str::uuid(), 'text' => 'Пам’ятаєш попереднє фото?',
        ])->assertOk();

        $requests = Http::recorded()->pluck(0);
        $first = $requests[0]->data();
        $second = $requests[1]->data();
        $this->assertFalse($first['store']);
        $this->assertSame('gpt-test', $first['model']);
        $this->assertSame('input_image', $first['input'][0]['content'][1]['type']);
        $this->assertStringStartsWith('data:image/png;base64,', $first['input'][0]['content'][1]['image_url']);
        $this->assertSame($reasoning, $second['input'][1]);
        $this->assertSame('final_answer', $second['input'][2]['phase']);
        $this->assertSame('Пам’ятаєш попереднє фото?', $second['input'][3]['content'][0]['text']);
        $this->assertDatabaseCount('inbox_messages', 0);
        $this->assertDatabaseCount('inbox_conversations', 0);
        $this->assertStringNotContainsString('opaque-reasoning', DB::table('gpt_sandbox_turns')->first()->response_output);
    }

    public function test_image_only_message_and_private_owner_scoped_media(): void
    {
        $owner = $this->owner();
        $chat = $this->configured($owner);
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->answer())]);
        $response = $this->actingAs($owner)->post(self::BASE.'/chats/'.$chat->id.'/messages', [
            'request_id' => (string) Str::uuid(), 'images' => [UploadedFile::fake()->image('private.jpg')],
        ])->assertOk();
        $url = $response->json('turn.images.0.url');
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $this->get($url)->headers->get('Cache-Control'));
        Storage::disk('local')->assertExists(SandboxTurn::first()->images[0]['path']);
        $other = $this->owner();
        $this->actingAs($other)->get($url)->assertNotFound();
        $this->postJson(self::BASE.'/chats/'.$chat->id.'/messages', [])->assertNotFound();
        $this->postJson(self::BASE.'/chats/'.$chat->id.'/turns/'.SandboxTurn::first()->id.'/retry')->assertNotFound();
    }

    public function test_duplicate_request_does_not_charge_or_save_twice(): void
    {
        $owner = $this->owner();
        $chat = $this->configured($owner);
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->answer())]);
        $data = ['request_id' => (string) Str::uuid(), 'text' => 'Привіт'];
        $this->actingAs($owner)->postJson(self::BASE.'/chats/'.$chat->id.'/messages', $data)->assertOk();
        $this->postJson(self::BASE.'/chats/'.$chat->id.'/messages', $data)->assertOk();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('gpt_sandbox_turns', 1);
    }

    public function test_failed_request_keeps_input_and_photo_and_can_be_retried_after_reload(): void
    {
        $owner = $this->owner();
        $chat = $this->configured($owner);
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push(['error' => ['message' => 'sk-test-private-key']], 429)->push($this->answer('Готово.'))]);
        $response = $this->actingAs($owner)->post(self::BASE.'/chats/'.$chat->id.'/messages', [
            'request_id' => (string) Str::uuid(), 'text' => 'Опишіть фото',
            'images' => [UploadedFile::fake()->image('retry.jpg')],
        ])->assertStatus(502)->assertJsonPath('turn.status', 'failed')->assertDontSee('sk-test-private-key');
        $this->getJson(self::BASE.'/state')->assertJsonPath('chat.turns.0.text', 'Опишіть фото');
        $this->getJson(self::BASE.'/state')->assertJsonPath('settings.connection_status', 'error');
        $this->postJson(self::BASE.'/chats/'.$chat->id.'/messages', ['request_id' => (string) Str::uuid(), 'text' => 'Інше'])
            ->assertConflict();
        $retry = self::BASE.'/chats/'.$chat->id.'/turns/'.$response->json('turn.id').'/retry';
        $this->postJson($retry)->assertOk()->assertJsonPath('turn.reply', 'Готово.');
        $this->getJson(self::BASE.'/state')->assertJsonPath('settings.connection_status', 'connected');
        $this->postJson($retry)->assertOk();
        Http::assertSentCount(2);
        $this->assertDatabaseCount('gpt_sandbox_turns', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_empty_invalid_and_oversized_uploads_never_reach_api(): void
    {
        $owner = $this->owner();
        $chat = $this->configured($owner);
        $url = self::BASE.'/chats/'.$chat->id.'/messages';
        $this->actingAs($owner)->postJson($url, ['request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->post($url, ['request_id' => (string) Str::uuid(), 'images' => [UploadedFile::fake()->create('file.svg', 1, 'image/svg+xml')]])->assertUnprocessable();
        $this->post($url, ['request_id' => (string) Str::uuid(), 'images' => [UploadedFile::fake()->image('large.jpg')->size(4097)]])->assertUnprocessable();
        $this->post($url, ['request_id' => (string) Str::uuid(), 'images' => array_map(fn () => UploadedFile::fake()->image('photo.jpg'), range(1, 4))])->assertUnprocessable();
        $this->assertDatabaseCount('gpt_sandbox_turns', 0);
        $this->assertEmpty(Storage::disk('local')->allFiles());
        Http::assertNothingSent();
    }

    public function test_chat_lock_blocks_concurrent_turns_but_does_not_mark_them_handled(): void
    {
        $owner = $this->owner();
        $chat = $this->configured($owner);
        $lock = Cache::lock('gpt-sandbox-chat:'.$chat->id, 120);
        $lock->get();
        $data = ['request_id' => (string) Str::uuid(), 'text' => 'Привіт'];
        $this->actingAs($owner)->postJson(self::BASE.'/chats/'.$chat->id.'/messages', $data)->assertConflict();
        $this->assertDatabaseCount('gpt_sandbox_turns', 0);
        $lock->release();
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->answer())]);
        $this->postJson(self::BASE.'/chats/'.$chat->id.'/messages', $data)->assertOk();
    }

    public function test_new_chat_preserves_previous_chat_and_excludes_its_context(): void
    {
        $owner = $this->owner();
        $old = $this->configured($owner);
        $old->turns()->create(['request_id' => Str::uuid(), 'text' => 'Старий контекст', 'model' => 'gpt-test', 'status' => 'completed']);
        $new = $this->actingAs($owner)->postJson(self::BASE.'/chats')->assertCreated()->json('chat.id');
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->answer())]);
        $this->postJson(self::BASE.'/chats/'.$new.'/messages', ['request_id' => (string) Str::uuid(), 'text' => 'Новий запит'])->assertOk();
        Http::assertSent(fn ($request) => count($request['input']) === 1 && $request['input'][0]['content'][0]['text'] === 'Новий запит');
        $this->assertDatabaseCount('gpt_sandbox_chats', 2);
        $this->assertDatabaseHas('gpt_sandbox_turns', ['chat_id' => $old->id, 'text' => 'Старий контекст']);
    }

    public function test_incomplete_or_empty_api_output_is_not_saved_as_a_success(): void
    {
        $owner = $this->owner();
        $chat = $this->configured($owner);
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push(['status' => 'incomplete', 'output' => []])->push(['status' => 'completed', 'output' => []])]);
        $this->actingAs($owner)->postJson(self::BASE.'/chats/'.$chat->id.'/messages', ['request_id' => (string) Str::uuid(), 'text' => 'Привіт'])
            ->assertStatus(502)->assertJsonPath('turn.status', 'failed');
        $this->postJson(self::BASE.'/chats/'.$chat->id.'/turns/'.SandboxTurn::first()->id.'/retry')
            ->assertStatus(502)->assertJsonPath('turn.status', 'failed');
        $this->assertNull(SandboxTurn::first()->reply);
    }

    public function test_saved_key_is_not_marked_connected_until_openai_verifies_it(): void
    {
        $owner = $this->owner();
        $this->configured($owner);
        $this->actingAs($owner)->getJson(self::BASE.'/state')->assertJsonPath('settings.connection_status', 'saved');
        Http::fake(['api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-test']]])]);
        $this->postJson(self::BASE.'/connection')->assertOk()->assertJsonPath('settings.connection_status', 'connected');
        $this->getJson(self::BASE.'/state')->assertJsonPath('settings.connection_status', 'connected');
        $this->putJson(self::BASE.'/settings', ['api_key' => 'sk-different', 'model' => 'gpt-test'])
            ->assertOk()->assertJsonPath('settings.connection_status', 'saved');
        // Відповідь перевірки старого ключа не підтверджує щойно замінений.
        SandboxSetting::recordCheck($owner->id, 'sk-test-private-key');
        $this->getJson(self::BASE.'/state')->assertJsonPath('settings.connection_status', 'saved');
    }

    public function test_draft_key_verification_survives_save_but_does_not_verify_another_key(): void
    {
        $owner = $this->owner();
        Http::fake(['api.openai.com/v1/models' => Http::response(['data' => []])]);
        $this->actingAs($owner)->postJson(self::BASE.'/connection', ['api_key' => 'sk-checked-draft'])->assertOk();
        $this->putJson(self::BASE.'/settings', ['api_key' => 'sk-checked-draft', 'model' => 'gpt-test'])
            ->assertOk()->assertJsonPath('settings.connection_status', 'connected');
        $this->putJson(self::BASE.'/settings', ['api_key' => 'sk-not-checked', 'model' => 'gpt-test'])
            ->assertOk()->assertJsonPath('settings.connection_status', 'saved');
    }

    public function test_failed_check_does_not_leave_false_connected_status(): void
    {
        $owner = $this->owner();
        $this->configured($owner);
        SandboxSetting::recordCheck($owner->id, 'sk-test-private-key');
        Http::fake(['api.openai.com/v1/models' => Http::response(['error' => ['message' => 'secret']], 401)]);
        $this->actingAs($owner)->postJson(self::BASE.'/connection')->assertUnprocessable()
            ->assertJsonPath('settings.connection_status', 'error')->assertDontSee('secret');
        $this->getJson(self::BASE.'/state')->assertJsonPath('settings.connection_status', 'error');
    }

    public function test_switching_models_keeps_dialogue_without_replaying_incompatible_reasoning(): void
    {
        $owner = $this->owner();
        $chat = $this->configured($owner);
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->answer('Попередня відповідь.', [['type' => 'reasoning', 'encrypted_content' => 'old-model-reasoning', 'summary' => []]]))
            ->push($this->answer('Відповідає інша модель.'))]);
        $this->actingAs($owner)->postJson(self::BASE.'/chats/'.$chat->id.'/messages', ['request_id' => (string) Str::uuid(), 'text' => 'Привіт'])->assertOk();
        $this->putJson(self::BASE.'/settings', ['model' => 'gpt-other'])->assertOk();
        $this->postJson(self::BASE.'/chats/'.$chat->id.'/messages', ['request_id' => (string) Str::uuid(), 'text' => 'Продовжимо'])->assertOk();
        $request = Http::recorded()->last()[0]->data();
        $this->assertSame('gpt-other', $request['model']);
        $this->assertSame(['role' => 'assistant', 'content' => 'Попередня відповідь.'], $request['input'][1]);
        $this->assertStringNotContainsString('old-model-reasoning', json_encode($request));
    }
}
