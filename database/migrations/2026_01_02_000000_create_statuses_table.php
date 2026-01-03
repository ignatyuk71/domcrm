<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statuses', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();           // new, confirmed, in_progress, done, cancelled, shipped, paid, unpaid, etc.
            $table->string('name');                     // Людська назва
            $table->string('type')->default('order');   // order | payment (можна розширити)
            $table->string('icon')->nullable();         // bi-check2-circle, bi-x-circle...
            $table->string('color')->nullable();        // клас для бейджа або hex
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statuses');
    }
};
