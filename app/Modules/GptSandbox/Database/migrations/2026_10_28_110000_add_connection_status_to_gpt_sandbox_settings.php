<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gpt_sandbox_settings', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable();
            $table->string('connection_error', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gpt_sandbox_settings', function (Blueprint $table) {
            $table->dropColumn(['verified_at', 'connection_error']);
        });
    }
};
