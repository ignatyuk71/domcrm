<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('chat_ai_product_model_maps')) {
            return;
        }

        Schema::table('chat_ai_product_model_maps', function (Blueprint $table) {
            if (!Schema::hasColumn('chat_ai_product_model_maps', 'item_code')) {
                $table->string('item_code', 40)
                    ->nullable()
                    ->after('model_phrase');
            }

            if (!Schema::hasColumn('chat_ai_product_model_maps', 'collage_url')) {
                $table->string('collage_url', 2048)
                    ->nullable()
                    ->after('item_code');
            }

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
        if (!Schema::hasTable('chat_ai_product_model_maps')) {
            return;
        }

        Schema::table('chat_ai_product_model_maps', function (Blueprint $table) {
            $table->dropIndex('chat_ai_product_model_maps_model_active_idx');
            $table->dropUnique('chat_ai_product_model_maps_model_item_unique');

            $dropColumns = array_values(array_filter([
                Schema::hasColumn('chat_ai_product_model_maps', 'item_code') ? 'item_code' : null,
                Schema::hasColumn('chat_ai_product_model_maps', 'collage_url') ? 'collage_url' : null,
            ]));

            if ($dropColumns !== []) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
