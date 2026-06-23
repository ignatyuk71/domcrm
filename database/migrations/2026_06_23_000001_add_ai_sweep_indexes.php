<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Індекси під щохвилинний крон ai:sweep. Його головний запит має корельовані
 * підзапити по ai_runs.inbox_message_id + фільтр inbox_messages(direction, created_at),
 * які досі йшли без індексів. На малих таблицях байдуже, але навантаження росте
 * квадратично — ці індекси роблять запит миттєвим за будь-якого обсягу.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->index(['inbox_message_id', 'status'], 'ai_runs_inbox_message_id_status_index');
        });

        Schema::table('inbox_messages', function (Blueprint $table) {
            $table->index(['direction', 'created_at'], 'inbox_messages_direction_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropIndex('ai_runs_inbox_message_id_status_index');
        });

        Schema::table('inbox_messages', function (Blueprint $table) {
            $table->dropIndex('inbox_messages_direction_created_at_index');
        });
    }
};
