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
            $table->string('delivery_status_code', 32)->nullable()->after('delivery_cost')->index();
            $table->string('delivery_status_label')->nullable()->after('delivery_status_code');
            $table->string('delivery_status_description')->nullable()->after('delivery_status_label');
            $table->timestamp('delivery_status_updated_at')->nullable()->after('delivery_status_description');
            $table->timestamp('last_tracked_at')->nullable()->after('delivery_status_updated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_deliveries', function (Blueprint $table) {
            $table->dropColumn([
                'delivery_status_code',
                'delivery_status_label',
                'delivery_status_description',
                'delivery_status_updated_at',
                'last_tracked_at',
            ]);
        });
    }
};
