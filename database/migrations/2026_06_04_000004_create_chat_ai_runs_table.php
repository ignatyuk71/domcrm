<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_ai_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')
                ->constrained('chat_conversations')
                ->cascadeOnDelete();
            $table->foreignId('state_id')
                ->nullable()
                ->constrained('chat_ai_conversation_states')
                ->nullOnDelete();
            $table->foreignId('source_message_id')
                ->nullable()
                ->constrained('chat_messages')
                ->nullOnDelete();
            $table->foreignId('agent_id')
                ->constrained('chat_ai_agents')
                ->restrictOnDelete();
            $table->foreignId('prompt_version_id')
                ->nullable()
                ->constrained('chat_ai_prompt_versions')
                ->nullOnDelete();
            $table->foreignId('requested_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('stage_snapshot', 40)->nullable()->index();
            $table->string('status', 20)->index();
            $table->string('provider', 32)->default('openai')->index();
            $table->string('model', 120)->nullable()->index();
            $table->string('idempotency_key', 100)->nullable()->unique();

            $table->unsignedSmallInteger('input_messages')->default(0);
            $table->unsignedInteger('input_chars')->default(0);
            $table->unsignedInteger('output_chars')->default(0);
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->decimal('cost_usd', 12, 6)->nullable();

            $table->longText('output_text')->nullable();
            $table->json('meta_json')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at'], 'chat_ai_runs_conversation_created_idx');
            $table->index(['status', 'created_at'], 'chat_ai_runs_status_created_idx');
            $table->index('source_message_id', 'chat_ai_runs_source_message_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_ai_runs');
    }
};

