<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbox_conversation_id')->constrained('inbox_conversations')->cascadeOnDelete();
            $table->string('direction', 10);                       // in | out
            $table->string('sender', 20)->default('contact');      // contact | agent | ai
            $table->string('external_message_id')->nullable()->unique(); // Meta mid (ідемпотентність)
            $table->text('text')->nullable();
            $table->json('attachments')->nullable();               // [{type,url}]
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('inbox_conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_messages');
    }
};
