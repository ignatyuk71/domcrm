<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_channel_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('claude_enabled')->default(true);
            $table->boolean('gpt_enabled')->default(false);
            $table->string('default_provider', 16)->default('claude');
            $table->text('gpt_api_key')->nullable();
            $table->string('gpt_model', 120)->nullable();
            $table->timestamps();
        });
        DB::table('ai_channel_settings')->insert(['id' => 1, 'claude_enabled' => true, 'gpt_enabled' => false, 'default_provider' => 'claude']);
        Schema::table('inbox_conversations', function (Blueprint $table) {
            $table->string('ai_provider', 16)->nullable();
            $table->unsignedInteger('ai_provider_version')->default(0);
            $table->boolean('ai_checkout_active')->default(false)->index();
            $table->longText('ai_checkout_data')->nullable();
        });
        // Не перемикаємо незавершені оформлення, розпочаті до появи перемикача.
        DB::table('inbox_conversations')->where('ai_enabled', true)->whereNull('ai_order_summary')
            ->whereExists(function ($query) {
                $query->selectRaw('1')->from('inbox_messages')->whereColumn('inbox_messages.inbox_conversation_id', 'inbox_conversations.id')
                    ->where('direction', 'out')->where('sender', 'ai')->where('text', \App\Services\Ai\OrderTexts::all()['delivery_template'])
                    ->where(fn ($q) => $q->whereNull('inbox_conversations.ai_context_after_id')->orWhereColumn('inbox_messages.id', '>', 'inbox_conversations.ai_context_after_id'));
            })->update(['ai_checkout_active' => true]);
        Schema::create('gpt_inbox_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbox_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_message_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('provider_version');
            $table->string('status', 24)->default('prepared');
            $table->longText('payload');
            $table->unsignedInteger('next_index')->default(0);
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gpt_inbox_replies');
        Schema::table('inbox_conversations', function (Blueprint $table) {
            $table->dropIndex(['ai_checkout_active']);
            $table->dropColumn(['ai_provider', 'ai_provider_version', 'ai_checkout_active', 'ai_checkout_data']);
        });
        Schema::dropIfExists('ai_channel_settings');
    }
};
