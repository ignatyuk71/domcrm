<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novapay_connections', function (Blueprint $table) {
            $table->string('auth_error_code', 64)->nullable();
            $table->text('auth_error_message')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('novapay_connections', function (Blueprint $table) {
            $table->dropColumn(['auth_error_code', 'auth_error_message']);
        });
    }
};
