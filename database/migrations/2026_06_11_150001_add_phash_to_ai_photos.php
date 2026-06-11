<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Перцептивний відбиток фото (dHash, 16 hex): щоб упізнавати скріни
 * наших же фото від клієнтів точно, без вгадування моделлю.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_photos', function (Blueprint $table) {
            $table->string('phash', 16)->nullable()->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('ai_photos', function (Blueprint $table) {
            $table->dropColumn('phash');
        });
    }
};
