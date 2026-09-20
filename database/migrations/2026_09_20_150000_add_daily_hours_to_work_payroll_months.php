<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_payroll_months', function (Blueprint $table) {
            // Старі записи визначають норму за місяцем без переписування сум і ревізій.
            $table->unsignedTinyInteger('daily_hours')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('work_payroll_months', fn (Blueprint $table) => $table->dropColumn('daily_hours'));
    }
};
