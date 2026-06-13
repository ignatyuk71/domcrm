<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_conversations', function (Blueprint $table) {
            // Підсумок розмови для панелі менеджера («Про що тут»).
            $table->text('ai_summary')->nullable()->after('ai_paused_until');
            $table->timestamp('ai_summary_at')->nullable()->after('ai_summary');
            // Замовлення, зафіксоване ШІ (complete_order) — для картки в панелі.
            $table->string('ai_order_summary')->nullable()->after('ai_summary_at');
            $table->string('ai_order_payment', 64)->nullable()->after('ai_order_summary');
            $table->unsignedBigInteger('ai_order_product_id')->nullable()->after('ai_order_payment');
            $table->timestamp('ai_order_handled_at')->nullable()->after('ai_order_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('inbox_conversations', function (Blueprint $table) {
            $table->dropColumn([
                'ai_summary', 'ai_summary_at',
                'ai_order_summary', 'ai_order_payment', 'ai_order_product_id', 'ai_order_handled_at',
            ]);
        });
    }
};
