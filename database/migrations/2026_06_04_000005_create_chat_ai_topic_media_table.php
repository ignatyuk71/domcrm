<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_ai_topic_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained('chat_ai_topics')->cascadeOnDelete();
            $table->foreignId('saved_file_id')->nullable()->constrained('saved_files')->nullOnDelete();
            $table->string('label');
            $table->enum('media_type', ['image', 'size_chart', 'palette', 'promo', 'collage'])->default('image');
            $table->text('url')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['topic_id', 'is_active', 'sort_order'], 'chat_ai_topic_media_sort_idx');
            $table->index(['media_type', 'is_active'], 'chat_ai_topic_media_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_ai_topic_media');
    }
};
