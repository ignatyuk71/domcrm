<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chat_conversations')) {
            DB::statement("UPDATE chat_conversations SET meta = JSON_REMOVE(meta, '$.ai') WHERE meta IS NOT NULL");
        }

        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('chat_ai_topic_media');
        Schema::dropIfExists('chat_ai_response_rules');
        Schema::dropIfExists('chat_ai_topic_products');
        Schema::dropIfExists('chat_ai_topic_keywords');
        Schema::dropIfExists('chat_ai_topics');
        Schema::dropIfExists('chat_ai_settings');

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // AI-таблиці видалено повністю, відновлення не передбачено.
    }
};
