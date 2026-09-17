<?php

namespace Tests\Feature\Analytics;

use App\Models\Category;
use App\Services\Costs\ProductionCostService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionCostModelsMigrationTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        // MySQL DDL завершує транзакції: запускаємо перевірку на чистій схемі.
        RefreshDatabaseState::$migrated = false;
        $this->beforeApplicationDestroyed(function () {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_existing_private_values_and_revisions_are_unchanged_when_assigned_to_halluci(): void
    {
        $migration = require database_path('migrations/2026_09_17_120000_add_models_to_production_costs.php');
        $migration->down();
        $category = Category::create(['name' => 'Домашні капці Halluci (хутряні)']);
        $values = ['component' => 'cardboard', 'name' => 'Синтетична стара партія', 'purchased_on' => null, 'quantity' => 20,
            'inputs' => json_encode(['goods_uah' => '3600', 'shipping_uah' => null, 'sheet_length_cm' => 120, 'sheet_width_cm' => 80, 'blank_length_cm' => 25, 'blank_width_cm' => 9]),
            'total_uah' => 3600, 'unit_cost_uah' => 8.4375, 'request_key' => (string) Str::uuid(), 'version' => 3, 'created_at' => now(), 'updated_at' => now()];
        $id = DB::table('production_cost_batches')->insertGetId($values);
        DB::table('production_cost_batch_revisions')->insert(['batch_id' => $id, 'before' => null, 'after' => json_encode($values), 'created_at' => now()]);
        $before = (array) DB::table('production_cost_batches')->first();
        $audit = DB::table('production_cost_batch_revisions')->get()->toJson();
        $migration->up();
        $after = (array) DB::table('production_cost_batches')->first();
        $modelId = $after['model_id'];
        unset($after['model_id']);
        $this->assertEquals($before, $after);
        $this->assertSame($audit, DB::table('production_cost_batch_revisions')->get()->toJson());
        $this->assertDatabaseHas('production_cost_models', ['id' => $modelId, 'category_id' => $category->id]);
        $this->assertCount(1, app(ProductionCostService::class)->listing('cardboard', $modelId)['data']);
    }
}
