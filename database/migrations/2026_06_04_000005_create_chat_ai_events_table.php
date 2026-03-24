<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_ai_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')
                ->constrained('chat_conversations')
                ->cascadeOnDelete();
            $table->foreignId('state_id')
                ->nullable()
                ->constrained('chat_ai_conversation_states')
                ->nullOnDelete();
            $table->foreignId('run_id')
                ->nullable()
                ->constrained('chat_ai_runs')
                ->nullOnDelete();

            $table->string('event_type', 80)->index();
            $table->string('from_stage', 40)->nullable();
            $table->string('to_stage', 40)->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['conversation_id', 'created_at'], 'chat_ai_events_conversation_created_idx');
            $table->index(['event_type', 'created_at'], 'chat_ai_events_type_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_ai_events');
    }
};

