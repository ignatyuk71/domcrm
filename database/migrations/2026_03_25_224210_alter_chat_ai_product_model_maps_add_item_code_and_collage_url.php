<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_ai_product_model_maps', function (Blueprint $table) {
            $table->string('item_code', 40)
                ->nullable()
                ->after('model_phrase');

            $table->string('collage_url', 2048)
                ->nullable()
                ->after('item_code');

            $table->unique(
                ['model_phrase', 'item_code'],
                'chat_ai_product_model_maps_model_item_unique'
            );

            $table->index(
                ['model_phrase', 'is_active'],
                'chat_ai_product_model_maps_model_active_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('chat_ai_product_model_maps', function (Blueprint $table) {
            $table->dropIndex('chat_ai_product_model_maps_model_active_idx');
            $table->dropUnique('chat_ai_product_model_maps_model_item_unique');
            $table->dropColumn(['item_code', 'collage_url']);
        });
    }
};
