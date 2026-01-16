<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE facebook_messages MODIFY mid VARCHAR(255)');

        Schema::table('facebook_messages', function (Blueprint $table) {
            $table->timestamp('sent_at')->nullable()->after('attachments');
            $table->string('status', 32)->nullable()->after('is_private');
            $table->boolean('is_read')->default(false)->after('status');
        });

        DB::statement('DROP INDEX facebook_messages_mid_unique ON facebook_messages');
        DB::statement('CREATE UNIQUE INDEX facebook_messages_mid_unique ON facebook_messages (mid)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX facebook_messages_mid_unique ON facebook_messages');
        DB::statement('CREATE UNIQUE INDEX facebook_messages_mid_unique ON facebook_messages (`mid`(191))');

        Schema::table('facebook_messages', function (Blueprint $table) {
            $table->dropColumn(['sent_at', 'status', 'is_read']);
        });

        DB::statement('ALTER TABLE facebook_messages MODIFY mid TEXT');
    }
};
