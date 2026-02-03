<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('facebook_settings')) {
            return;
        }

        Schema::table('facebook_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('facebook_settings', 'instagram_account_id')) {
                $table->string('instagram_account_id')->nullable()->after('page_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('facebook_settings')) {
            return;
        }

        Schema::table('facebook_settings', function (Blueprint $table) {
            if (Schema::hasColumn('facebook_settings', 'instagram_account_id')) {
                $table->dropColumn('instagram_account_id');
            }
        });
    }
};
