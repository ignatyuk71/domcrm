<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_contacts', function (Blueprint $table) {
            // Коли ВОСТАННЄ пробували тягнути профіль (не коли вдалося):
            // тротлінг, щоб мертвий FB-профіль-АПІ не смикався на кожне повідомлення.
            $table->timestamp('profile_pic_checked_at')->nullable()->after('profile_pic');
        });
    }

    public function down(): void
    {
        Schema::table('inbox_contacts', function (Blueprint $table) {
            $table->dropColumn('profile_pic_checked_at');
        });
    }
};