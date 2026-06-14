<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_conversations', function (Blueprint $table) {
            // Watermark «прочитано» від Meta (FB message_reads / IG seen):
            // усі наші вихідні з sent_at <= цього часу клієнт переглянув.
            $table->timestamp('last_read_at')->nullable()->after('last_message_direction');
        });
    }

    public function down(): void
    {
        Schema::table('inbox_conversations', function (Blueprint $table) {
            $table->dropColumn('last_read_at');
        });
    }
};
