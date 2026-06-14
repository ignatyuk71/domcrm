<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_conversations', function (Blueprint $table) {
            // Коли надіслано фоллоу-ап мовчазному ліду (один раз на розмову).
            $table->timestamp('follow_up_sent_at')->nullable()->after('last_read_at');
        });

        Schema::table('ai_settings', function (Blueprint $table) {
            // Через скільки годин тиші слати фоллоу-ап. 0 — вимкнено.
            $table->unsignedSmallInteger('follow_up_hours')->default(3)->after('operator_pause_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('inbox_conversations', function (Blueprint $table) {
            $table->dropColumn('follow_up_sent_at');
        });
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn('follow_up_hours');
        });
    }
};
