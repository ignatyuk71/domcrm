<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_ai_prompt_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')
                ->constrained('chat_ai_agents')
                ->cascadeOnDelete();
            $table->string('stage', 40)->index();
            $table->unsignedSmallInteger('version');
            $table->longText('system_prompt');
            $table->json('policy_json')->nullable();
            $table->boolean('is_current')->default(false)->index();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['agent_id', 'stage', 'version'], 'chat_ai_prompt_unique');
            $table->index(['agent_id', 'stage', 'is_current'], 'chat_ai_prompt_current_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_ai_prompt_versions');
    }
};

