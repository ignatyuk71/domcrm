<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кеш «пост → лінійка»: визначення (відбиток або ШІ) робиться ОДИН раз на пост,
 * усі наступні коментарі того ж поста використовують готовий результат.
 * ai_photo_group_id = null → «не розпізнано» (теж кешується: одразу відкривач).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_post_lines', function (Blueprint $table) {
            $table->id();
            $table->string('post_id')->unique();
            $table->foreignId('ai_photo_group_id')->nullable()->constrained('ai_photo_groups')->nullOnDelete();
            $table->string('source', 16); // hash | ai | none
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_post_lines');
    }
};
