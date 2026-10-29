<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gpt_sandbox_settings', function (Blueprint $table) {
            $table->foreignId('meta_connection_id')->nullable()->constrained('meta_connections')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('gpt_sandbox_settings', fn (Blueprint $table) => $table->dropConstrainedForeignId('meta_connection_id'));
    }
};
