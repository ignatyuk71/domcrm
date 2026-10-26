<?php

namespace Tests\Feature\Inbox;

use App\Models\AiSetting;
use App\Models\InboxContact;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\MetaConnection;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RemoveOpenAiSettingsTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        // DDL у MySQL завершує транзакції — для міграції потрібна чиста тестова схема.
        RefreshDatabaseState::$migrated = false;
        $this->beforeApplicationDestroyed(function () {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_removal_preserves_claude_configuration_and_historical_runs(): void
    {
        $global = AiSetting::global();
        $global->update(['api_key' => 'sk-ant-keep', 'model' => 'claude-opus-4-8', 'debounce_seconds' => 0]);
        // Перевіряємо перехід зі схеми, яка вже працює на сервері.
        $migration = require database_path('migrations/2026_10_26_180000_remove_openai_connection_settings.php');
        $migration->down();
        DB::table('ai_settings')->where('id', $global->id)->update([
            'provider' => 'openai', 'openai_api_key' => 'encrypted-unused-key', 'openai_model' => 'unused-model',
        ]);
        $connection = MetaConnection::create(['page_id' => 'keep-history', 'page_name' => 'Магазин', 'page_access_token' => 'test', 'status' => 'active']);
        $contact = InboxContact::create(['meta_connection_id' => $connection->id, 'channel' => 'facebook', 'external_id' => 'keep-contact']);
        $conversation = InboxConversation::create(['meta_connection_id' => $connection->id, 'inbox_contact_id' => $contact->id, 'channel' => 'facebook']);
        $message = InboxMessage::create(['inbox_conversation_id' => $conversation->id, 'direction' => 'in', 'sender' => 'contact', 'text' => 'Збережіть переписку']);
        $runId = DB::table('ai_runs')->insertGetId([
            'inbox_conversation_id' => $conversation->id, 'inbox_message_id' => $message->id,
            'status' => 'replied', 'provider' => 'openai', 'model' => 'historical-model',
        ]);
        $runColumns = Schema::getColumnListing('ai_runs');

        $migration->up();

        foreach (['provider', 'openai_api_key', 'openai_model'] as $column) {
            $this->assertFalse(Schema::hasColumn('ai_settings', $column));
        }
        $this->assertSame('sk-ant-keep', $global->fresh()->api_key);
        $this->assertSame('claude-opus-4-8', $global->fresh()->model);
        $this->assertSame(0, $global->fresh()->debounce_seconds);
        $this->assertSame($runColumns, Schema::getColumnListing('ai_runs'));
        $this->assertDatabaseHas('ai_runs', ['id' => $runId, 'provider' => 'openai', 'model' => 'historical-model']);
        $this->assertDatabaseHas('inbox_messages', ['id' => $message->id, 'text' => 'Збережіть переписку']);
        $this->assertDatabaseHas('inbox_conversations', ['id' => $conversation->id]);
    }
}
