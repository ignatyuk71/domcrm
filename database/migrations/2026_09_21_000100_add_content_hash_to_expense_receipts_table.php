<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_receipts', function (Blueprint $table) {
            $table->string('content_hash', 64)->nullable();
            $table->unique(['payment_id', 'content_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('expense_receipts', function (Blueprint $table) {
            $table->dropUnique(['payment_id', 'content_hash']);
            $table->dropColumn('content_hash');
        });
    }
};
