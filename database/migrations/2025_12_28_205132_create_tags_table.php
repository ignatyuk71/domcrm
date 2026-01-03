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
        Schema::create('tags', function (Blueprint $table) {
            $table->id();

            // Службові поля тегу
            $table->string('code', 50)->unique();     // urgent, callback, viber, call
            $table->string('name', 100);              // Назва для UI
            $table->string('color', 20)->nullable();  // red / blue / amber
            $table->string('icon', 50)->nullable();   // bi-telephone, bi-clock

            $table->timestamps();

            // Індекси
            $table->index('code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
