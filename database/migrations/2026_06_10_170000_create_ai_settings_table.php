<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            // null = глобальний рядок (ключ API, модель); інакше — налаштування конкретного магазину
            $table->foreignId('meta_connection_id')->nullable()->unique()
                ->constrained('meta_connections')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('model')->nullable();        // глобально: claude-sonnet-4-6 і т.п.
            $table->text('api_key')->nullable();        // глобально, шифрується кастом
            $table->text('system_prompt')->nullable();  // інструкція на магазин
            $table->json('schedule')->nullable();       // розклад (на майбутню фазу)
            $table->timestamps();
        });

        Schema::table('inbox_conversations', function (Blueprint $table) {
            $table->boolean('ai_enabled')->default(true)->after('chat_status_id');
        });
    }

    public function down(): void
    {
        Schema::table('inbox_conversations', function (Blueprint $table) {
            $table->dropColumn('ai_enabled');
        });
        Schema::dropIfExists('ai_settings');
    }
};
