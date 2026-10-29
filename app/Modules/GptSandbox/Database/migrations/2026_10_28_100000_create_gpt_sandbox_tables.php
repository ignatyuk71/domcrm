<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gpt_sandbox_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('api_key');
            $table->string('model', 120);
            $table->timestamps();
        });

        Schema::create('gpt_sandbox_chats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('gpt_sandbox_turns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUuid('chat_id')->constrained('gpt_sandbox_chats')->cascadeOnDelete();
            $table->uuid('request_id');
            $table->text('text')->nullable();
            $table->json('images')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('model', 120);
            $table->text('reply')->nullable();
            $table->longText('response_output')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->unique(['chat_id', 'request_id']);
            $table->index(['chat_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gpt_sandbox_turns');
        Schema::dropIfExists('gpt_sandbox_chats');
        Schema::dropIfExists('gpt_sandbox_settings');
    }
};
