<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_deliveries', function (Blueprint $table) {
            $table->string('street_ref', 36)
                ->nullable()
                ->after('street_name')
                ->index();
            $table->string('address_ref', 36)
                ->nullable()
                ->after('street_ref')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('order_deliveries', function (Blueprint $table) {
            $table->dropColumn(['street_ref', 'address_ref']);
        });
    }
};
