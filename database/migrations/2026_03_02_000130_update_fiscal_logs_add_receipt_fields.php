<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fiscal_logs', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('fiscal_receipt_id')->constrained('orders')->nullOnDelete();
            $table->string('receipt_uuid')->nullable()->after('order_id');
            $table->string('fiscal_code')->nullable()->after('receipt_uuid');
            $table->string('status')->nullable()->after('fiscal_code');
            $table->string('visual_url')->nullable()->after('status');

            $table->index('order_id');
            $table->index('receipt_uuid');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fiscal_logs', function (Blueprint $table) {
            $table->dropColumn(['order_id', 'receipt_uuid', 'fiscal_code', 'status', 'visual_url']);
        });
    }
};
