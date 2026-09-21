<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_payroll_months', function (Blueprint $table) {
            // NULL — успадкувати попередній оклад; явний 0 припиняє його нарахування.
            $table->unsignedInteger('monthly_salary_cents')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('work_payroll_months', fn (Blueprint $table) => $table->dropColumn('monthly_salary_cents'));
    }
};
