<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_ai_topic_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained('chat_ai_topics')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['topic_id', 'product_id'], 'chat_ai_topic_products_unique');
            $table->index(['topic_id', 'is_active', 'sort_order'], 'chat_ai_topic_products_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_ai_topic_products');
    }
};
