<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_ai_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(true)->index();
            $table->string('default_agent_code', 80)->nullable();
            $table->unsignedSmallInteger('reply_delay_seconds')->default(12);
            $table->boolean('allow_assigned_conversations')->default(true)->index();
            $table->unsignedSmallInteger('max_messages')->default(12);
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_ai_settings');
    }
};
