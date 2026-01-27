<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_delivery_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_delivery_id')
                ->constrained('order_deliveries')
                ->cascadeOnDelete();
            $table->string('status_code', 64);
            $table->string('status_label', 255)->nullable();
            $table->string('status_description', 255)->nullable();
            $table->string('source_code', 64)->nullable();
            $table->timestamp('entered_at');
            $table->timestamp('exited_at')->nullable();
            $table->timestamps();

            $table->index(['order_delivery_id', 'status_code', 'entered_at'], 'ods_hist_delivery_status_entered');
            $table->index(['status_code', 'entered_at'], 'ods_hist_status_entered');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_delivery_status_histories');
    }
};
