<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')
                ->constrained('chat_conversations')
                ->cascadeOnDelete();
            $table->foreignId('parent_message_id')
                ->nullable()
                ->constrained('chat_messages')
                ->nullOnDelete();
            $table->string('external_message_id', 191)->nullable()->unique();
            $table->string('external_parent_message_id', 191)->nullable()->index();
            $table->enum('direction', ['inbound', 'outbound'])->index();
            $table->enum('message_type', ['text', 'image', 'video', 'audio', 'file', 'system'])
                ->default('text')
                ->index();
            $table->enum('delivery_status', ['pending', 'sent', 'delivered', 'read', 'failed'])
                ->default('pending')
                ->index();
            $table->enum('source', ['webhook', 'operator', 'sync', 'system'])
                ->default('webhook')
                ->index();
            $table->longText('text')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'sent_at', 'id'], 'chat_messages_conversation_sent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
