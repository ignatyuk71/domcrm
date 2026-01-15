<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL не дозволяє UNIQUE для TEXT без довжини, тому використовуємо префікс.
        DB::statement('CREATE UNIQUE INDEX facebook_messages_mid_unique ON facebook_messages (`mid`(191))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX facebook_messages_mid_unique ON facebook_messages');
    }
};
