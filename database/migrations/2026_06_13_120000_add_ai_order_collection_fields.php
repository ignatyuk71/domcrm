<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_conversations', function (Blueprint $table) {
            // Структуроване замовлення, яке збирає ШІ (1-5 пар + дані доставки).
            $table->json('ai_order_items')->nullable()->after('ai_order_product_id');       // [{title,color,size,qty,price}]
            $table->string('ai_order_customer_name')->nullable()->after('ai_order_items');   // ПІБ отримувача
            $table->string('ai_order_phone', 32)->nullable()->after('ai_order_customer_name');
            $table->text('ai_order_address')->nullable()->after('ai_order_phone');           // місто/село + № відділення
            // Клієнт попросив повні реквізити / IBAN → бот замовкає, працівник дошле.
            $table->boolean('ai_order_needs_iban')->default(false)->after('ai_order_address');
        });
    }

    public function down(): void
    {
        Schema::table('inbox_conversations', function (Blueprint $table) {
            $table->dropColumn([
                'ai_order_items', 'ai_order_customer_name', 'ai_order_phone',
                'ai_order_address', 'ai_order_needs_iban',
            ]);
        });
    }
};
