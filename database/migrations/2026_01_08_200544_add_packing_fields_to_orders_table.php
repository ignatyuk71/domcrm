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
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('packing_status', ['pending', 'processing', 'packed'])
                ->nullable()
                ->default('pending')
                ->after('payment_status')
                ->index();
            $table->boolean('is_priority')
                ->default(false)
                ->after('packing_status')
                ->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['packing_status', 'is_priority']);
        });
    }
};
