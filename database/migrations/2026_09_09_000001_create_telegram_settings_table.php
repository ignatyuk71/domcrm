<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->text('bot_token')->nullable();
            $table->string('chat_id', 32)->nullable();
            $table->string('bot_username')->nullable();
            $table->string('bot_name')->nullable();
            $table->string('chat_title')->nullable();
            $table->boolean('enabled')->default(false);
            $table->json('permissions')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_test_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_settings');
    }
};
