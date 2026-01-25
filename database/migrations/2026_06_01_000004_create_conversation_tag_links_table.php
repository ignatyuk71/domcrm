<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_tag_links', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')
                ->constrained('conversations')
                ->cascadeOnDelete();

            $table->foreignId('conversation_tag_id')
                ->constrained('conversation_tags')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['conversation_id', 'conversation_tag_id'], 'conv_tag_links_unique');
            $table->index('conversation_id', 'conv_tag_links_conversation_id_idx');
            $table->index('conversation_tag_id', 'conv_tag_links_tag_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_tag_links');
    }
};
