<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('external_id')->nullable()->after('source_id')->index();
            $table->boolean('needs_review')->default(false)->after('external_id')->index();
            $table->unique(['source_id', 'external_id']); // ідемпотентність на рівні замовлення
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['source_id', 'external_id']);
            $table->dropColumn(['external_id', 'needs_review']);
        });
    }
};
