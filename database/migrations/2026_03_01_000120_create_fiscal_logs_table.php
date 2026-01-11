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
        Schema::create('fiscal_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('fiscal_receipt_id')
                ->nullable()
                ->constrained('fiscal_receipts')
                ->nullOnDelete();

            $table->string('stage')->nullable();
            $table->string('method')->nullable();
            $table->json('request_body')->nullable();
            $table->json('response_body')->nullable();
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamps();

            $table->index('fiscal_receipt_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_logs');
    }
};
