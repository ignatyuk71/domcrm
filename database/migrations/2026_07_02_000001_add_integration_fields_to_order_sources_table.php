<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_sources', function (Blueprint $table) {
            $table->boolean('is_integration')->default(false)->index()->after('is_default');
            $table->string('mode', 10)->nullable()->after('is_integration');   // push | pull
            $table->string('adapter', 32)->nullable()->after('mode');          // custom | shopify | opencart | prom
            $table->string('api_key', 80)->nullable()->unique()->after('adapter');
            $table->string('api_secret', 120)->nullable()->after('api_key');
            $table->json('pull_config')->nullable()->after('api_secret');
            $table->boolean('is_enabled')->default(true)->after('pull_config');
            $table->timestamp('last_pulled_at')->nullable()->after('is_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('order_sources', function (Blueprint $table) {
            $table->dropUnique(['api_key']);
            $table->dropColumn([
                'is_integration', 'mode', 'adapter', 'api_key',
                'api_secret', 'pull_config', 'is_enabled', 'last_pulled_at',
            ]);
        });
    }
};
