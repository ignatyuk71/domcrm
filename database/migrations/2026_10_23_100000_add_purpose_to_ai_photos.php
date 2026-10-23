<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_photos', function (Blueprint $table) {
            // Наявні фото залишаються товарними; пояснення не є кольором чи колажем.
            $table->string('purpose', 32)->default('product')->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('ai_photos', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
    }
};
