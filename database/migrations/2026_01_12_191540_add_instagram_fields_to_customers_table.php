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
        if (!Schema::hasTable('customers')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            // Визначаємо колонку, після якої безпечно вставляти
            $afterColumn = Schema::hasColumn('customers', 'fb_user_id') ? 'fb_user_id' : 'email';

            if (!Schema::hasColumn('customers', 'instagram_user_id')) {
                $table->string('instagram_user_id')->nullable()->after($afterColumn);
            }

            if (!Schema::hasColumn('customers', 'instagram_username')) {
                $table->string('instagram_username')->nullable()->after('instagram_user_id');
            }

            if (!Schema::hasColumn('customers', 'instagram_user_id')) {
                // nothing to index
            } else {
                $table->index('instagram_user_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('customers')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'instagram_user_id')) {
                $table->dropIndex(['instagram_user_id']);
            }
            $columns = [];
            if (Schema::hasColumn('customers', 'instagram_user_id')) {
                $columns[] = 'instagram_user_id';
            }
            if (Schema::hasColumn('customers', 'instagram_username')) {
                $columns[] = 'instagram_username';
            }
            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }
};
