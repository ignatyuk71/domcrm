<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_cost_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->unique()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('legacy_key', 64)->nullable()->unique();
            $table->timestamps();
        });
        $name = 'Домашні капці Halluci (хутряні)';
        $model = DB::table('production_cost_models')->insertGetId([
            'category_id' => DB::table('categories')->where('name', $name)->orderBy('id')->value('id'),
            'name' => $name, 'legacy_key' => 'halluci-fur', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Schema::table('production_cost_batches', function (Blueprint $table) use ($model) {
            // Наявні розрахунки власник відніс до Halluci; гроші, версії й аудит не переписуємо.
            $table->foreignId('model_id')->default($model)->constrained('production_cost_models')->restrictOnDelete();
            $table->index(['model_id', 'component', 'id'], 'production_cost_model_component_id');
        });
    }

    public function down(): void
    {
        if (DB::table('production_cost_models')->whereNull('legacy_key')->exists()) {
            throw new RuntimeException('Спочатку збережіть окремі розрахунки моделей: відкат об’єднав би їхні дані.');
        }
        Schema::table('production_cost_batches', function (Blueprint $table) {
            // MySQL використовує складений індекс для FK: спочатку прибираємо зв’язок.
            $table->dropForeign(['model_id']);
            $table->dropIndex('production_cost_model_component_id');
            $table->dropColumn('model_id');
        });
        Schema::dropIfExists('production_cost_models');
    }
};
