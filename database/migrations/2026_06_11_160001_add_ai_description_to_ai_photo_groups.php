<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Опис для ШІ» на лінійку: знання продавчині (маломірність, відмінності,
 * догляд, як впізнати на фото). Цифри (ціни/розміри/наявність) сюди НЕ пишуться —
 * вони завжди живі з таблиць товарів.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_photo_groups', function (Blueprint $table) {
            $table->text('ai_description')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('ai_photo_groups', function (Blueprint $table) {
            $table->dropColumn('ai_description');
        });
    }
};
