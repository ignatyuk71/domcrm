<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Режим каталогу агента:
 *  - all      — вся база товарів (лінії з описами + «інші товари»);
 *  - showcase — ЛИШЕ вітрина (групи Галереї ШІ): що не розкладено — того для ШІ не існує.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->string('catalog_mode', 16)->default('all')->after('system_prompt');
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn('catalog_mode');
        });
    }
};
