<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fiscal_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();

            $table->foreignId('cashier_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->enum('status', ['opened', 'closing', 'closed', 'error'])->index();
            $table->timestamp('opened_at')->index();
            $table->timestamp('closed_at')->nullable()->index();
            $table->string('z_report_id')->nullable();
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_shifts');
    }
};
