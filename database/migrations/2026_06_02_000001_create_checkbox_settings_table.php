<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkbox_settings', function (Blueprint $table) {
            $table->id();

            $table->string('api_url')->nullable();
            $table->string('license_key')->nullable();
            $table->string('login')->nullable();
            $table->string('password')->nullable();

            $table->boolean('enabled')->default(true);
            $table->boolean('queue_enabled')->default(true);

            $table->time('open_time')->nullable();
            $table->time('close_time')->nullable();
            $table->time('queue_process_time')->nullable();

            $table->timestamp('last_opened_at')->nullable();
            $table->timestamp('last_closed_at')->nullable();
            $table->timestamp('last_queue_processed_at')->nullable();

            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index('enabled');
            $table->index('queue_enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkbox_settings');
    }
};
