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
        Schema::create('fiscal_receipts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            $table->foreignId('shift_id')
                ->nullable()
                ->constrained('fiscal_shifts')
                ->nullOnDelete();

            $table->foreignId('cashier_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->uuid('uuid')->unique();
            $table->string('fiscal_code')->nullable()->index();
            $table->enum('type', ['sell', 'return', 'service_in', 'service_out']);
            $table->enum('status', ['pending', 'processing', 'success', 'error', 'canceled'])->index();
            $table->string('payload_hash');
            $table->string('check_link')->nullable();
            $table->unsignedInteger('total_amount')->default(0);
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->boolean('is_offline')->default(false);
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(['payload_hash', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_receipts');
    }
};
