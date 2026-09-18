<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_payroll_months', function (Blueprint $table) {
            $table->string('rate_mode', 12)->default('hourly');
            $table->unsignedInteger('daily_rate_cents')->nullable();
            $table->unsignedInteger('expense_cents')->default(0);
            $table->integer('adjustment_cents')->default(0);
            $table->string('adjustment_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('work_payroll_months', function (Blueprint $table) {
            $table->dropColumn(['rate_mode', 'daily_rate_cents', 'expense_cents', 'adjustment_cents', 'adjustment_reason']);
        });
    }
};
