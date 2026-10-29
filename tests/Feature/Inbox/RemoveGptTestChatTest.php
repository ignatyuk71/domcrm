<?php

namespace Tests\Feature\Inbox;

use App\Models\{AiChannelSetting, AiSetting, Product, User};
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\{Crypt, DB, Schema, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

class RemoveGptTestChatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        try {
            // Цей тест змінює схему, тому не використовує транзакції чи відкат сторонніх міграцій.
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
            RefreshDatabaseState::$migrated = false;
        } finally { parent::tearDown(); }
    }

    public function test_removal_is_reversible_and_preserves_working_channels_and_catalog(): void
    {
        Storage::fake('local');
        foreach (glob(database_path('migrations/*gpt_sandbox*.php')) as $file) (require $file)->up();
        $owner = User::factory()->create(['role' => 'owner']);
        $live = AiChannelSetting::current();
        $live->update(['gpt_api_key' => 'sk-working-key', 'gpt_model' => 'gpt-test', 'gpt_enabled' => true]);
        AiSetting::global()->update(['api_key' => 'sk-claude-working']);
        $product = Product::create(['title' => 'Робочий товар', 'sale_price' => '399.00', 'is_active' => true]);
        $chatId = (string) Str::uuid();
        DB::table('gpt_sandbox_settings')->insert(['user_id' => $owner->id, 'api_key' => Crypt::encryptString('sk-retired-test-key'), 'model' => 'gpt-test']);
        DB::table('gpt_sandbox_chats')->insert(['id' => $chatId, 'user_id' => $owner->id]);
        DB::table('gpt_sandbox_turns')->insert(['id' => (string) Str::ulid(), 'chat_id' => $chatId, 'request_id' => (string) Str::uuid(),
            'text' => 'Історія тестового чату', 'status' => 'completed', 'model' => 'gpt-test']);
        Storage::disk('local')->put('gpt-sandbox/receipt.png', 'Приватне тестове фото');
        $migration = require database_path('migrations/2026_10_29_210000_remove_gpt_test_chat.php');
        $migration->up();

        foreach (['gpt_sandbox_settings', 'gpt_sandbox_chats', 'gpt_sandbox_turns'] as $table) $this->assertFalse(Schema::hasTable($table));
        Storage::disk('local')->assertMissing('gpt-sandbox/receipt.png');
        $archive = 'retired/gpt-test-chat/'.hash('sha256', DB::connection()->getDatabaseName());
        $encrypted = Storage::disk('local')->get($archive.'/tables.enc');
        $this->assertStringNotContainsString('Історія тестового чату', $encrypted);
        $tables = json_decode(Crypt::decryptString($encrypted), true);
        $this->assertSame('Історія тестового чату', $tables['gpt_sandbox_turns'][0]['text']);
        $this->assertSame('sk-working-key', $live->fresh()->gpt_api_key);
        $this->assertSame('sk-claude-working', AiSetting::global()->api_key);
        $this->assertSame('399.00', $product->fresh()->sale_price);
        $this->assertDatabaseCount('inbox_messages', 0);
        $migration->down();
        $this->assertDatabaseHas('gpt_sandbox_chats', ['id' => $chatId]);
        $this->assertDatabaseHas('gpt_sandbox_turns', ['text' => 'Історія тестового чату']);
        Storage::disk('local')->assertExists('gpt-sandbox/receipt.png');
    }
}
