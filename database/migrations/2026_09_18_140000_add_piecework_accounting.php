<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_employees', function (Blueprint $table) {
            $table->string('payment_type', 12)->default('hourly')->index();
        });
        Schema::create('work_piecework_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('employee_id')->constrained('work_employees')->restrictOnDelete();
            $table->date('work_date');
            $table->string('description', 200);
            $table->unsignedInteger('quantity');
            $table->string('unit', 12);
            $table->string('pricing_mode', 12);
            $table->unsignedInteger('unit_rate_cents')->nullable();
            $table->unsignedInteger('total_cents');
            $table->unsignedInteger('paid_cents')->default(0);
            $table->string('note', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'work_date']);
            $table->index(['work_date', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_piecework_entries');
        Schema::table('work_employees', function (Blueprint $table) {
            $table->dropColumn('payment_type');
        });
    }
};
