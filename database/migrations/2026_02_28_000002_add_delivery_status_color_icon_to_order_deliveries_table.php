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
        Schema::table('order_deliveries', function (Blueprint $table) {
            $table->string('delivery_status_color', 32)->nullable()->after('delivery_status_description');
            $table->string('delivery_status_icon', 64)->nullable()->after('delivery_status_color');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_deliveries', function (Blueprint $table) {
            $table->dropColumn(['delivery_status_color', 'delivery_status_icon']);
        });
    }
};
