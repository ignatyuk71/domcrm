<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            // Липка пауза тепер у ХВИЛИНАХ (раніше були години). Дефолт 3 хв.
            $table->unsignedSmallInteger('operator_pause_minutes')->default(3)->after('operator_pause_hours');
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn('operator_pause_minutes');
        });
    }
};
