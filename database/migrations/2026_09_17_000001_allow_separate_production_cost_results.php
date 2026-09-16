<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Розрахунки з окремими результатами не мають спільної ціни на пару.
        Schema::table('production_cost_batches', function (Blueprint $table) {
            $table->decimal('unit_cost_uah', 16, 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Не підміняємо відсутню спільну ціну нулем під час відкату.
        if (DB::table('production_cost_batches')->whereNull('unit_cost_uah')->exists()) {
            throw new RuntimeException('Відкат неможливий: існують розрахунки без спільної ціни на пару.');
        }
        Schema::table('production_cost_batches', function (Blueprint $table) {
            $table->decimal('unit_cost_uah', 16, 6)->nullable(false)->change();
        });
    }
};
