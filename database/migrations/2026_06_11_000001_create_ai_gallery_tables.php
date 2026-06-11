<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Галерея ШІ: групи (модельні лінійки) → фото (колажі/кольори) → відмітки товарів.
 * Тип фото не зберігається — він похідний: 1 товар на фото = «колір», 2+ = «колаж».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_photo_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('ai_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_photo_group_id')->constrained('ai_photo_groups')->cascadeOnDelete();
            $table->string('path');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Товари групи (всі кольори лінійки)
        Schema::create('ai_photo_group_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_photo_group_id')->constrained('ai_photo_groups')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unique(['ai_photo_group_id', 'product_id']);
        });

        // Хто зображений на конкретному фото
        Schema::create('ai_photo_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_photo_id')->constrained('ai_photos')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unique(['ai_photo_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_photo_product');
        Schema::dropIfExists('ai_photo_group_product');
        Schema::dropIfExists('ai_photos');
        Schema::dropIfExists('ai_photo_groups');
    }
};
