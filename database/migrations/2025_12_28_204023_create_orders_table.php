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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            // Ідентифікація
            $table->string('order_number', 32)->unique();
            $table->string('source', 24)->nullable()->index(); // site / instagram / phone / allegro / olx

            // Статуси
            $table->string('status', 24)->default('new')->index(); // new / in_work / packed / shipped / canceled
            $table->string('payment_status', 24)->default('unpaid')->index(); // unpaid / prepayment / paid / refund

            // Звʼязки
            $table->foreignId('customer_id')
                ->nullable()
                ->constrained('customers')
                ->nullOnDelete();

            $table->foreignId('manager_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('packer_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Фінанси
            $table->char('currency', 3)->nullable()->index(); // UAH / PLN

            // Службове
            $table->text('comment_internal')->nullable();
            $table->text('search_blob')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
