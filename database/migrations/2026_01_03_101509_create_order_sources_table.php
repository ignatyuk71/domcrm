<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_sources', function (Blueprint $table) {
            $table->id();

            $table->string('code')->unique();          // site / instagram / allegro
            $table->string('name');                    // Сайт / Instagram
            $table->string('type')->default('order');  // order
            $table->string('icon')->nullable();        // icon class
            $table->string('color')->nullable();       // badge color
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_sources');
    }
};
