<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Контекст повідомлення: на ЩО відповів клієнт.
 *  - {type: reply, mid}            — відповідь на конкретне повідомлення (цитата)
 *  - {type: story, url, local}     — відповідь на сторіс (local = наша копія, бо URL Meta протухає)
 *  - {type: share, url, local}     — пересланий пост/медіа
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_messages', function (Blueprint $table) {
            $table->json('context')->nullable()->after('attachments');
        });
    }

    public function down(): void
    {
        Schema::table('inbox_messages', function (Blueprint $table) {
            $table->dropColumn('context');
        });
    }
};
