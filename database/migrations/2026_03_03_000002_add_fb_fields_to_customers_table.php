<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('customers')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'fb_user_id')) {
                $table->string('fb_user_id')->nullable()->unique()->after('email');
            }
            if (!Schema::hasColumn('customers', 'fb_profile_pic')) {
                $table->string('fb_profile_pic')->nullable()->after('fb_user_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('customers')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'fb_user_id')) {
                $table->dropUnique(['fb_user_id']);
            }
            $columns = [];
            if (Schema::hasColumn('customers', 'fb_user_id')) {
                $columns[] = 'fb_user_id';
            }
            if (Schema::hasColumn('customers', 'fb_profile_pic')) {
                $columns[] = 'fb_profile_pic';
            }
            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }
};
