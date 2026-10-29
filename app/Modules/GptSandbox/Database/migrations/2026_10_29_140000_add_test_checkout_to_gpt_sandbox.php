<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gpt_sandbox_chats', function (Blueprint $table) {
            $table->longText('checkout_data')->nullable();
        });
        Schema::table('gpt_sandbox_turns', function (Blueprint $table) {
            $table->longText('checkout_messages')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gpt_sandbox_turns', fn (Blueprint $table) => $table->dropColumn('checkout_messages'));
        Schema::table('gpt_sandbox_chats', fn (Blueprint $table) => $table->dropColumn('checkout_data'));
    }
};
