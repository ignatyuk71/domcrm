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
        // Реквізити доставки та одержувача для замовлення (1:1)
        Schema::create('order_deliveries', function (Blueprint $table) {
            $table->id();

            // Звʼязок із замовленням (одна доставка на одне замовлення)
            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            // Перевізник
            $table->string('carrier', 24)
                ->default('nova_poshta')
                ->index(); // nova_poshta / inpost / dpd ...

            // Тип доставки
            $table->string('delivery_type', 24)
                ->index(); // warehouse / postomat / courier

            // ТТН
            $table->string('ttn', 64)->nullable()->index();

            // 🔥 ХТО ПЛАТИТЬ ЗА ДОСТАВКУ
            $table->enum('delivery_payer', ['sender', 'recipient'])
                ->default('recipient')
                ->index(); // sender = магазин, recipient = клієнт

            // 🔥 ВАРТІСТЬ ДОСТАВКИ
            $table->decimal('delivery_cost', 10, 2)
                ->nullable(); // може рахуватись пізніше

            // Місто
            $table->string('city_ref', 36)->nullable()->index();
            $table->string('city_name')->nullable();

            // Відділення / поштомат
            $table->string('warehouse_ref', 36)->nullable()->index();
            $table->string('warehouse_name')->nullable();

            // Курʼєрська адреса
            $table->string('street_name')->nullable();
            $table->string('building')->nullable();
            $table->string('apartment')->nullable();
            $table->string('address_note')->nullable();

            // Одержувач
            $table->string('recipient_name')->nullable();
            $table->string('recipient_phone')->nullable();

            $table->timestamps();

            // Гарантія: одна доставка = одне замовлення
            $table->unique('order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_deliveries');
    }
};
