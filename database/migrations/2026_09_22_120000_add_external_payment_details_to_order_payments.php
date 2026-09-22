<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_payments', function (Blueprint $table) {
            $table->string('provider', 64)->nullable();
            $table->decimal('paid_amount', 12, 2)->nullable();
            $table->string('transaction_id')->nullable();
            $table->timestamp('paid_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_payments', function (Blueprint $table) {
            $table->dropColumn(['provider', 'paid_amount', 'transaction_id', 'paid_at']);
        });
    }
};
