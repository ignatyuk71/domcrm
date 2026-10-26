<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn(['provider', 'openai_api_key', 'openai_model']);
        });
        // Історію запусків, переписки та заявки залишаємо без змін.
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->string('provider', 20)->nullable();
            $table->text('openai_api_key')->nullable();
            $table->string('openai_model', 100)->nullable();
        });
        // Відкат відновлює структуру, але не видалений ключ підключення.
    }
};
