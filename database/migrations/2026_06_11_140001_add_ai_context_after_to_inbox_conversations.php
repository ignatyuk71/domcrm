<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Скинути памʼять ШІ»: агент читає історію розмови лише ПІСЛЯ цього id.
 * Повідомлення в чаті лишаються — просто агент їх більше не бачить.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('ai_context_after_id')->nullable()->after('ai_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('inbox_conversations', function (Blueprint $table) {
            $table->dropColumn('ai_context_after_id');
        });
    }
};
