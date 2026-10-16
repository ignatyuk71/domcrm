<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novapay_operations', function (Blueprint $table) {
            $table->string('counterparty_iban', 34)->nullable();
            // Час зміни статусу у NovaPay, а не вигаданий час бухгалтерського проведення.
            $table->dateTime('status_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('novapay_operations', function (Blueprint $table) {
            $table->dropColumn(['counterparty_iban', 'status_changed_at']);
        });
    }
};
