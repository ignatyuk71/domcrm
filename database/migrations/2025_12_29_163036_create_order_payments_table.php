<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();

            // 1:1 з orders
            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            // Метод оплати (з форми)
            $table->string('method', 24)->index(); // cod / card / transfer / prepay

            // Передоплата (якщо method=prepay)
            $table->decimal('prepay_amount', 12, 2)->nullable();

            // Валюта
            $table->char('currency', 3)->default('UAH')->index();

            // (опц.) Коментар по оплаті
            $table->string('note')->nullable();

            $table->timestamps();

            // одна оплата на одне замовлення
            $table->unique('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payments');
    }
};
