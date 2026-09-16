<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sole_inventory_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->unique()->constrained()->restrictOnDelete();
            $table->date('opening_date');
            $table->json('opening_balances');
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->unsignedSmallInteger('safety_days')->default(14);
            $table->unsignedSmallInteger('lookback_days')->default(30);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('sole_inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('sole_inventory_plans')->restrictOnDelete();
            $table->string('size', 16);
            $table->string('kind', 16);
            $table->integer('quantity');
            $table->date('movement_date');
            $table->string('note', 500)->nullable();
            $table->uuid('request_key')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at');
            $table->index(['plan_id', 'movement_date']);
        });

        Schema::create('sole_inventory_plan_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('sole_inventory_plans')->restrictOnDelete();
            $table->json('before')->nullable();
            $table->json('after');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sole_inventory_plan_revisions');
        Schema::dropIfExists('sole_inventory_movements');
        Schema::dropIfExists('sole_inventory_plans');
    }
};
