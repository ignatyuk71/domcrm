<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Автоматика агента:
 *  - ai_settings.operator_pause_hours — липка пауза: оператор написав у розмову →
 *    ШІ мовчить у ній стільки годин (0 = вимкнено);
 *  - ai_settings.comment_settings — автовідповідь на коментарі (json: enabled,
 *    facebook, instagram, mode all|keywords, keywords, opener);
 *  - inbox_conversations.ai_paused_until — до якого часу ШІ на паузі в розмові;
 *  - inbox_comments.dm_message_id — mid нашої приватної відповіді (звʼязка з розмовою);
 *  - inbox_comments.matched_group_name — яку лінійку ШІ розпізнав з поста.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('operator_pause_hours')->default(6)->after('catalog_mode');
            $table->json('comment_settings')->nullable()->after('operator_pause_hours');
        });

        Schema::table('inbox_conversations', function (Blueprint $table) {
            $table->timestamp('ai_paused_until')->nullable()->after('ai_context_after_id');
        });

        Schema::table('inbox_comments', function (Blueprint $table) {
            $table->string('dm_message_id')->nullable()->index()->after('status');
            $table->string('matched_group_name')->nullable()->after('dm_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn(['operator_pause_hours', 'comment_settings']);
        });
        Schema::table('inbox_conversations', function (Blueprint $table) {
            $table->dropColumn('ai_paused_until');
        });
        Schema::table('inbox_comments', function (Blueprint $table) {
            $table->dropColumn(['dm_message_id', 'matched_group_name']);
        });
    }
};
