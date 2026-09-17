<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_cost_models', function (Blueprint $table) {
            $table->string('cost_profile', 32)->default('sewn');
        });
        // Прив’язуємо профіль один раз; перейменування категорії не змінює склад виробу.
        $categories = DB::table('categories')->where('name', 'Капці для вулиці (хутряні)')->select('id');
        DB::table('production_cost_models')->where(function ($query) use ($categories) {
            $query->whereIn('category_id', $categories)->orWhere('name', 'Капці для вулиці (хутряні)');
        })->update(['cost_profile' => 'outdoor']);
    }

    public function down(): void
    {
        Schema::table('production_cost_models', fn (Blueprint $table) => $table->dropColumn('cost_profile'));
    }
};
