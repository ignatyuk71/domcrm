<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novapay_operations', function (Blueprint $table) {
            $table->string('document_number', 128)->nullable();
            foreach (['sender', 'recipient'] as $party) {
                $table->string($party.'_name')->nullable();
                $table->string($party.'_iban', 34)->nullable();
                $table->string($party.'_tax_code', 32)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('novapay_operations', function (Blueprint $table) {
            $table->dropColumn(['document_number', 'sender_name', 'sender_iban', 'sender_tax_code', 'recipient_name', 'recipient_iban', 'recipient_tax_code']);
        });
    }
};
