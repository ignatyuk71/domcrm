<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        // Для MySQL/MariaDB явно розширюємо ENUM новим станом.
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("
                ALTER TABLE orders
                MODIFY packing_status ENUM('pending', 'processing', 'packed', 'skipped')
                NULL DEFAULT 'pending'
            ");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // Повертаємо відкладені замовлення у звичайний pending перед відкатом ENUM.
            DB::table('orders')
                ->where('packing_status', 'skipped')
                ->update(['packing_status' => 'pending']);

            DB::statement("
                ALTER TABLE orders
                MODIFY packing_status ENUM('pending', 'processing', 'packed')
                NULL DEFAULT 'pending'
            ");
        }
    }
};
