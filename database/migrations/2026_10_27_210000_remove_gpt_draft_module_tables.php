<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Видаляємо лише власні таблиці скасованого модуля GPT.
        Schema::dropIfExists('gpt_runs');
        Schema::dropIfExists('gpt_dialogues');
        Schema::dropIfExists('gpt_settings');
    }

    public function down(): void
    {
        // Дані видаленого модуля відновлюються лише з резервної копії.
    }
};
