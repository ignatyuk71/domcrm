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
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            // Основне
            $table->string('title');                 // Назва товару
            $table->string('sku')->nullable();       // Артикул

            // Розміри за замовчуванням
            $table->unsignedInteger('weight_g')->nullable(); // Вага (г)
            $table->decimal('length_cm', 8, 1)->nullable();  // Довжина (см)
            $table->decimal('width_cm', 8, 1)->nullable();   // Ширина (см)
            $table->decimal('height_cm', 8, 1)->nullable();  // Висота (см)

            // Ціни та валюта
            $table->string('currency', 3)->nullable();       // UAH / PLN / EUR
            $table->decimal('cost_price', 10, 2)->nullable(); // Закупка
            $table->decimal('sale_price', 10, 2)->nullable(); // Ціна продажу

            // Склад
            $table->unsignedInteger('stock_qty')->nullable(); // Залишок
            $table->unsignedInteger('min_stock')->nullable(); // Мін. залишок

            // Контент
            $table->text('description')->nullable();          // Опис
            $table->string('main_photo_path')->nullable();    // Головне фото

            // Службове
            $table->boolean('is_active')->default(true);      // Активний товар

            $table->timestamps();

            // Індекси для CRM
            $table->index('sku');
            $table->index('category');
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
