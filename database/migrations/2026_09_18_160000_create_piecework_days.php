<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_piecework_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('work_employees')->restrictOnDelete();
            $table->date('work_date');
            $table->unsignedBigInteger('amount_cents')->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'work_date']);
            $table->index('work_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_piecework_days');
    }
};
