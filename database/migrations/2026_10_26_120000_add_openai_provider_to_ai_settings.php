<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            // Провайдер читається лише з глобального рядка; NULL залишає Claude активним.
            $table->string('provider', 20)->nullable();
            $table->text('openai_api_key')->nullable();
            $table->string('openai_model', 100)->nullable();
        });
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->string('provider', 20)->nullable();
            $table->string('model', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', fn (Blueprint $table) => $table->dropColumn(['provider', 'model']));
        Schema::table('ai_settings', fn (Blueprint $table) => $table->dropColumn(['provider', 'openai_api_key', 'openai_model']));
    }
};
