<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_ai_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')
                ->constrained('chat_ai_runs')
                ->cascadeOnDelete();
            $table->foreignId('conversation_id')
                ->constrained('chat_conversations')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('decision', 20)->index();
            $table->longText('edited_text')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'user_id'], 'chat_ai_feedback_run_user_unique');
            $table->index(['conversation_id', 'created_at'], 'chat_ai_feedback_conversation_created_idx');
            $table->index(['decision', 'created_at'], 'chat_ai_feedback_decision_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_ai_feedback');
    }
};

