<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Прибираємо повністю чат, AI-агента та Meta-інтеграцію.
     * Порядок: спочатку залежні (children), потім батьківські (parents).
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        // AI events / feedback / runs / state
        Schema::dropIfExists('chat_ai_events');
        Schema::dropIfExists('chat_ai_feedback');
        Schema::dropIfExists('chat_ai_runs');
        Schema::dropIfExists('chat_ai_conversation_states');

        // AI довідники
        Schema::dropIfExists('chat_ai_prompt_versions');
        Schema::dropIfExists('chat_ai_product_model_maps');
        Schema::dropIfExists('chat_ai_knowledge_items');
        Schema::dropIfExists('chat_ai_settings');
        Schema::dropIfExists('chat_ai_agents');

        // Сам чат
        Schema::dropIfExists('chat_message_attachments');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
        Schema::dropIfExists('chat_contacts');
        Schema::dropIfExists('chat_stages');

        // Meta / шаблони / legacy
        Schema::dropIfExists('meta_connections');
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('facebook_messages');
        Schema::dropIfExists('facebook_settings');
        Schema::dropIfExists('conversation_meta');
        Schema::dropIfExists('conversation_tags');
        Schema::dropIfExists('conversations');

        Schema::enableForeignKeyConstraints();

        // Чат-поля у customers — спочатку індекси (по одному, бо можуть не існувати), потім колонки
        if (Schema::hasTable('customers')) {
            if (Schema::hasColumn('customers', 'fb_user_id')) {
                try {
                    Schema::table('customers', fn (Blueprint $t) => $t->dropUnique(['fb_user_id']));
                } catch (\Throwable) {
                }
                try {
                    Schema::table('customers', fn (Blueprint $t) => $t->dropIndex(['fb_user_id']));
                } catch (\Throwable) {
                }
            }

            if (Schema::hasColumn('customers', 'instagram_user_id')) {
                try {
                    Schema::table('customers', fn (Blueprint $t) => $t->dropIndex(['instagram_user_id']));
                } catch (\Throwable) {
                }
                try {
                    Schema::table('customers', fn (Blueprint $t) => $t->dropUnique(['instagram_user_id']));
                } catch (\Throwable) {
                }
            }

            Schema::table('customers', function (Blueprint $table) {
                $columns = ['fb_user_id', 'instagram_user_id', 'instagram_username', 'fb_profile_pic'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('customers', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    /**
     * Зворотний шлях не відновлює дані — повернути чат можна лише з бекапу.
     * Тут лише повертаємо колонки в customers, щоб міграцію можна було rollback'нути без помилки.
     */
    public function down(): void
    {
        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table) {
                if (!Schema::hasColumn('customers', 'fb_user_id')) {
                    $table->string('fb_user_id')->nullable()->after('email');
                }
                if (!Schema::hasColumn('customers', 'instagram_user_id')) {
                    $table->string('instagram_user_id')->nullable()->after('fb_user_id');
                }
                if (!Schema::hasColumn('customers', 'instagram_username')) {
                    $table->string('instagram_username')->nullable()->after('instagram_user_id');
                }
                if (!Schema::hasColumn('customers', 'fb_profile_pic')) {
                    $table->string('fb_profile_pic')->nullable()->after('instagram_username');
                }
            });
        }
    }
};
