<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Безпечне додавання category/color до products
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (!Schema::hasColumn('products', 'category_id')) {
                    $table->foreignId('category_id')->nullable()->after('sku')->constrained('categories')->nullOnDelete();
                }
                if (!Schema::hasColumn('products', 'color_id')) {
                    $table->foreignId('color_id')->nullable()->after('category_id')->constrained('colors')->nullOnDelete();
                }
            });
        }

        // Безпечне додавання FB/Instagram полів до customers
        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table) {
                if (!Schema::hasColumn('customers', 'fb_user_id')) {
                    $table->string('fb_user_id')->nullable()->after('email');
                    $table->index('fb_user_id');
                }
                if (!Schema::hasColumn('customers', 'fb_profile_pic')) {
                    $table->string('fb_profile_pic')->nullable()->after('fb_user_id');
                }
                if (!Schema::hasColumn('customers', 'instagram_user_id')) {
                    $table->string('instagram_user_id')->nullable()->after('fb_user_id');
                    $table->index('instagram_user_id');
                }
                if (!Schema::hasColumn('customers', 'instagram_username')) {
                    $table->string('instagram_username')->nullable()->after('instagram_user_id');
                }
            });
        }

        // Безпечне додавання Instagram до facebook_settings
        if (Schema::hasTable('facebook_settings')) {
            Schema::table('facebook_settings', function (Blueprint $table) {
                if (!Schema::hasColumn('facebook_settings', 'instagram_account_id')) {
                    $table->string('instagram_account_id')->nullable()->after('page_id');
                }
            });
        }
    }

    public function down(): void
    {
        // Без деструктивних дій, щоб не втрачати дані при відкаті
    }
};
