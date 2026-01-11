<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facebook_settings', function (Blueprint $table) {
            $table->id();
            $table->string('page_id')->nullable();
            $table->text('access_token')->nullable();
            $table->string('verify_token')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_settings');
    }
};
