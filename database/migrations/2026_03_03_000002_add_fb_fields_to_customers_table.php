<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('fb_user_id')->nullable()->unique()->after('email');
            $table->string('fb_profile_pic')->nullable()->after('fb_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['fb_user_id']);
            $table->dropColumn(['fb_user_id', 'fb_profile_pic']);
        });
    }
};
