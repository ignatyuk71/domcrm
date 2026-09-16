<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sole_inventory_plans', function (Blueprint $table) {
            // Вже внесені залишки не видаємо за закуплені партії та не видаляємо.
            $table->string('basis', 16)->default('legacy');
        });
        Schema::create('sole_inventory_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('sole_inventory_plans')->restrictOnDelete();
            $table->date('received_on');
            $table->json('quantities');
            $table->string('note', 500)->nullable();
            $table->uuid('request_key')->unique();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['plan_id', 'received_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sole_inventory_batches');
        Schema::table('sole_inventory_plans', fn (Blueprint $table) => $table->dropColumn('basis'));
    }
};
