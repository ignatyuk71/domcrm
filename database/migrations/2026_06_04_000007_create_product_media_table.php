<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_media', function (Blueprint $table) {
            $table->id();
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
            $table->foreignId('saved_file_id')
                ->nullable()
                ->constrained('saved_files')
                ->nullOnDelete();

            $table->string('media_type', 20)->default('image')->index();
            $table->string('url')->nullable();
            $table->string('title', 180)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0)->index();
            $table->boolean('is_primary')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['product_id', 'is_active', 'sort_order'], 'product_media_product_active_sort_idx');
            $table->index(['variant_id', 'is_active'], 'product_media_variant_active_idx');
            $table->index(['color_id', 'is_active'], 'product_media_color_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_media');
    }
};

