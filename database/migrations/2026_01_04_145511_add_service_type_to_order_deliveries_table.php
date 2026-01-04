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
            // Додаємо enum для типу сервісу Нової Пошти
            $table->enum('service_type', [
                'WarehouseWarehouse', // Відділення - Відділення
                'WarehousePostomat',  // Відділення - Поштомат
                'WarehouseDoors'      // Відділення - Адреса (Кур'єр)
            ])->default('WarehouseWarehouse')->after('delivery_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_deliveries', function (Blueprint $table) {
            // Видаляємо поле при відкоті міграції
            $table->dropColumn('service_type');
        });
    }
};