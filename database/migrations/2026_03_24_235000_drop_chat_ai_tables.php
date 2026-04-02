<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chat_conversations')) {
            DB::table('chat_conversations')
                ->select(['id', 'meta'])
                ->orderBy('id')
                ->chunkById(200, function ($rows): void {
                    foreach ($rows as $row) {
                        $meta = json_decode((string) ($row->meta ?? 'null'), true);
                        if (!is_array($meta) || !array_key_exists('ai', $meta)) {
                            continue;
                        }

                        unset($meta['ai']);

                        DB::table('chat_conversations')
                            ->where('id', $row->id)
                            ->update([
                                'meta' => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            ]);
                    }
                });
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
