<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_deliveries', function (Blueprint $table) {
            // Дані перевізника відокремлені від реквізитів, які редагує менеджер.
            $table->decimal('np_document_cost', 10, 2)->nullable();
            $table->string('np_payer_type', 16)->nullable();
            $table->string('np_cost_ttn', 64)->nullable();
            $table->string('np_cost_status_code', 16)->nullable();
            $table->dateTime('np_document_date')->nullable();
            $table->dateTime('np_cost_checked_at')->nullable();
            $table->dateTime('np_cost_attempted_at')->nullable();
            $table->index(['carrier', 'np_cost_attempted_at'], 'deliveries_np_cost_sync');
        });
    }

    public function down(): void
    {
        Schema::table('order_deliveries', function (Blueprint $table) {
            $table->dropIndex('deliveries_np_cost_sync');
            $table->dropColumn(['np_document_cost', 'np_payer_type', 'np_cost_ttn', 'np_cost_status_code', 'np_document_date', 'np_cost_checked_at', 'np_cost_attempted_at']);
        });
    }
};
