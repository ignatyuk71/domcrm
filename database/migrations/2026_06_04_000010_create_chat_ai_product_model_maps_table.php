<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_ai_product_model_maps', function (Blueprint $table) {
            $table->id();
            $table->string('model_phrase', 160)->index();
            $table->string('item_code', 40)->nullable();
            $table->string('collage_url', 2048)->nullable();
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->foreignId('variant_id')
                ->nullable()
                ->constrained('product_variants')
                ->nullOnDelete();
            $table->foreignId('color_id')
                ->nullable()
                ->constrained('colors')
                ->nullOnDelete();
            $table->string('size_hint', 50)->nullable();
            $table->unsignedSmallInteger('priority')->default(100)->index();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['product_id', 'is_active'], 'chat_ai_model_map_product_active_idx');
            $table->index(['model_phrase', 'is_active'], 'chat_ai_product_model_maps_model_active_idx');
            $table->unique(
                ['model_phrase', 'item_code'],
                'chat_ai_product_model_maps_model_item_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_ai_product_model_maps');
    }
};
