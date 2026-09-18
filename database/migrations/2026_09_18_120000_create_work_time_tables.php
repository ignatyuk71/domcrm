<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_employees', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->string('name', 100);
            $table->string('position', 100)->nullable();
            $table->date('archived_on')->nullable()->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('work_time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('work_employees')->restrictOnDelete();
            $table->date('work_date');
            // Соті частки години: 7,5 год = 750. NULL відрізняється від явно введеного нуля.
            $table->unsignedSmallInteger('hour_units')->nullable();
            $table->string('note', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'work_date']);
            $table->index('work_date');
        });
        Schema::create('work_payroll_months', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('work_employees')->restrictOnDelete();
            $table->date('month');
            // Ставка належить конкретному місяцю і не змінює попередні розрахунки.
            $table->unsignedInteger('hourly_rate_cents')->nullable();
            $table->unsignedInteger('bonus_cents')->default(0);
            $table->unsignedInteger('paid_cents')->default(0);
            $table->string('note', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['employee_id', 'month']);
        });
        Schema::create('work_time_revisions', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('before')->nullable();
            $table->json('after');
            $table->timestamp('created_at');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_time_revisions');
        Schema::dropIfExists('work_payroll_months');
        Schema::dropIfExists('work_time_entries');
        Schema::dropIfExists('work_employees');
    }
};
