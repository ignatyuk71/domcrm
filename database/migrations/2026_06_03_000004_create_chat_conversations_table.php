<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_connection_id')
                ->constrained('meta_connections')
                ->restrictOnDelete();
            $table->foreignId('contact_id')
                ->constrained('chat_contacts')
                ->cascadeOnDelete();
            $table->foreignId('customer_id')
                ->nullable()
                ->constrained('customers')
                ->nullOnDelete();
            $table->foreignId('stage_id')
                ->constrained('chat_stages')
                ->restrictOnDelete();
            $table->foreignId('assigned_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->enum('status', ['open', 'closed', 'archived'])->default('open')->index();
            $table->string('external_thread_id', 191)->nullable();
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->text('last_message_preview')->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamp('last_inbound_at')->nullable()->index();
            $table->timestamp('last_outbound_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('snooze_until')->nullable()->index();
            $table->timestamp('closed_at')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique('contact_id', 'chat_conversations_contact_unique');
            $table->index(['status', 'last_message_at'], 'chat_conversations_status_last_message_idx');
            $table->index(['customer_id', 'status'], 'chat_conversations_customer_status_idx');
            $table->index('stage_id', 'chat_conversations_stage_idx');
            $table->unique(
                ['meta_connection_id', 'external_thread_id'],
                'chat_conversations_thread_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_conversations');
    }
};
