<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Коментарі під постами FB/IG. Окремо від розмов: коментар — не діалог.
 * status: new → dm_sent (після приватної відповіді в директ).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_connection_id')->constrained('meta_connections')->cascadeOnDelete();
            $table->string('channel', 16); // facebook | instagram
            $table->string('post_id');
            $table->text('post_excerpt')->nullable();
            $table->string('post_image')->nullable();      // локальна копія прев'ю поста
            $table->string('comment_id')->unique();        // дедуп вебхука
            $table->string('parent_comment_id')->nullable();
            $table->string('from_id');
            $table->string('from_name')->nullable();
            $table->text('text')->nullable();
            $table->string('status', 16)->default('new');  // new | dm_sent
            $table->timestamp('commented_at')->nullable();
            $table->timestamps();
            $table->index(['channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_comments');
    }
};
