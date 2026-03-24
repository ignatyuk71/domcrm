<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_ai_conversation_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')
                ->constrained('chat_conversations')
                ->cascadeOnDelete();
            $table->foreignId('agent_id')
                ->constrained('chat_ai_agents')
                ->restrictOnDelete();

            $table->string('stage', 40)->default('interest')->index();
            $table->string('last_intent', 64)->nullable()->index();
            $table->boolean('intent_purchase')->default(false)->index();
            $table->boolean('requires_human')->default(false)->index();

            $table->json('slots_json')->nullable();
            $table->json('missing_slots_json')->nullable();

            $table->foreignId('selected_product_id')
                ->nullable()
                ->constrained('products')
                ->nullOnDelete();
            $table->foreignId('selected_variant_id')
                ->nullable()
                ->constrained('product_variants')
                ->nullOnDelete();
            $table->foreignId('selected_color_id')
                ->nullable()
                ->constrained('colors')
                ->nullOnDelete();
            $table->string('selected_size', 50)->nullable();

            $table->foreignId('last_customer_message_id')
                ->nullable()
                ->constrained('chat_messages')
                ->nullOnDelete();
            $table->foreignId('last_agent_message_id')
                ->nullable()
                ->constrained('chat_messages')
                ->nullOnDelete();

            $table->timestamp('stage_updated_at')->nullable()->index();
            $table->unsignedInteger('turn_count')->default(0);
            $table->timestamps();

            $table->unique('conversation_id', 'chat_ai_state_conversation_unique');
            $table->index(['stage', 'updated_at'], 'chat_ai_state_stage_updated_idx');
            $table->index(['intent_purchase', 'stage'], 'chat_ai_state_purchase_stage_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_ai_conversation_states');
    }
};

