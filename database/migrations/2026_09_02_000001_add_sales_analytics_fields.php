<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('sale_type', 16)->default('retail')->index()->after('currency');
            $table->index(['created_at', 'currency'], 'orders_analytics_date_currency_idx');
        });

        Schema::table('order_items', function (Blueprint $table) {
            // Snapshot не дає історичній маржі змінюватися після редагування товару.
            $table->decimal('cost_price', 12, 2)->nullable()->after('price');
        });

        // Для старих позицій фіксуємо поточну закупівельну ціну як стартову історичну.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('UPDATE order_items oi INNER JOIN products p ON p.id = oi.product_id SET oi.cost_price = p.cost_price WHERE oi.cost_price IS NULL');
        }
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('cost_price');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_analytics_date_currency_idx');
            $table->dropColumn('sale_type');
        });
    }
};
