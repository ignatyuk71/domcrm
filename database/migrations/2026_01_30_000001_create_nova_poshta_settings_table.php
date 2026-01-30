<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nova_poshta_settings', function (Blueprint $table) {
            $table->id();
            $table->string('api_key')->nullable();
            $table->string('sender_ref', 64)->nullable();
            $table->string('contact_ref', 64)->nullable();
            $table->string('sender_phone', 32)->nullable();
            $table->string('sender_city_ref', 64)->nullable();
            $table->string('sender_warehouse_ref', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nova_poshta_settings');
    }
};
