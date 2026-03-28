<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('chat_ai_agents')) {
            return;
        }

        DB::table('chat_ai_agents')
            ->where('code', 'sales_assistant_v1')
            ->update([
                'model' => 'gpt-5-mini',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('chat_ai_agents')) {
            return;
        }

        DB::table('chat_ai_agents')
            ->where('code', 'sales_assistant_v1')
            ->where('model', 'gpt-5-mini')
            ->update([
                'model' => 'gpt-4.1-mini',
                'updated_at' => now(),
            ]);
    }
};
