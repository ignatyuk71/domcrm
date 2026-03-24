<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_ai_agents', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true)->index();
            $table->string('provider', 32)->default('openai')->index();
            $table->string('model', 120)->nullable();
            $table->decimal('temperature', 4, 2)->default(0.30);
            $table->unsignedSmallInteger('max_output_tokens')->default(300);
            $table->json('config_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_ai_agents');
    }
};

