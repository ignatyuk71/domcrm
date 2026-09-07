<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_changes', function (Blueprint $table) {
            $table->id();
            // Знімки залишаються доступними після видалення замовлення, користувача чи статусу.
            $table->unsignedBigInteger('order_id');
            $table->string('order_number', 32);
            $table->string('old_status', 64)->nullable();
            $table->unsignedBigInteger('old_status_id')->nullable();
            $table->string('old_status_name')->nullable();
            $table->string('new_status', 64)->nullable();
            $table->unsignedBigInteger('new_status_id')->nullable();
            $table->string('new_status_name')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('source', 64);
            $table->string('reason', 500);
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at', 6);
            $table->index(['order_id', 'id']);
            $table->index(['source', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_changes');
    }
};
