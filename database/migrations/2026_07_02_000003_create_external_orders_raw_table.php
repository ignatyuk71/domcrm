<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_orders_raw', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('order_sources')->cascadeOnDelete();
            $table->string('external_order_id')->nullable();
            $table->string('adapter', 32)->nullable();
            $table->json('payload');
            $table->string('status', 20)->default('received'); // received|processing|processed|failed
            $table->text('error')->nullable();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['source_id', 'external_order_id']); // ідемпотентність
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_orders_raw');
    }
};
