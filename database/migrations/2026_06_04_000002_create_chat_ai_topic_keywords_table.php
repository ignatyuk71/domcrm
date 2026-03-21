<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_ai_topic_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained('chat_ai_topics')->cascadeOnDelete();
            $table->string('phrase');
            $table->enum('match_type', ['positive', 'negative'])->default('positive');
            $table->unsignedSmallInteger('weight')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['topic_id', 'phrase', 'match_type'], 'chat_ai_topic_keywords_unique');
            $table->index(['match_type', 'is_active']);
            $table->index('phrase');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_ai_topic_keywords');
    }
};
