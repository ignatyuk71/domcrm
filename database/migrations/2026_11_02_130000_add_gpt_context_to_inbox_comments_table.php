<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_comments', function (Blueprint $table): void {
            $table->foreignId('inbox_conversation_id')->nullable()->constrained('inbox_conversations')->nullOnDelete();
            $table->longText('gpt_context')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inbox_comments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('inbox_conversation_id');
            $table->dropColumn('gpt_context');
        });
    }
};
