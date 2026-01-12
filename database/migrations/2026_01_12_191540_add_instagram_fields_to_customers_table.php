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
        Schema::table('customers', function (Blueprint $table) {
            $table->string('instagram_user_id')->nullable()->after('fb_user_id');
            $table->string('instagram_username')->nullable()->after('instagram_user_id');
            $table->index('instagram_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['instagram_user_id']);
            $table->dropColumn(['instagram_user_id', 'instagram_username']);
        });
    }
};
