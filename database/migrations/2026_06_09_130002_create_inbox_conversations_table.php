<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_connection_id')->constrained('meta_connections')->cascadeOnDelete();
            $table->foreignId('inbox_contact_id')->constrained('inbox_contacts')->cascadeOnDelete();
            $table->string('channel');                          // facebook | instagram
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_text', 500)->nullable(); // прев'ю для списку
            $table->string('last_message_direction', 10)->nullable(); // in | out
            $table->unsignedInteger('unread_count')->default(0);
            $table->string('status')->default('open');          // open | closed
            $table->timestamps();

            $table->unique('inbox_contact_id'); // 1 контакт = 1 діалог
            $table->index('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_conversations');
    }
};
