<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('order_sources')->cascadeOnDelete();
            $table->string('external_id');                 // ідентифікатор товару на сайті
            $table->string('external_size')->default('');  // розмір як прийшов із сайту (частина ключа)
            $table->string('external_sku')->nullable();    // SKU як прийшов із сайту (для довідки)
            $table->string('external_name')->nullable();   // назва як на сайті (для оператора)
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source_id', 'external_id', 'external_size']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_products');
    }
};
