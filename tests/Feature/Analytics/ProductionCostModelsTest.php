<?php

namespace Tests\Feature\Analytics;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Costs\ProductionCostModels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionCostModelsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/production-costs/models';

    private function owner(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    }

    private function category(string $name = 'Тестові капці'): Category
    {
        return Category::create(['name' => $name, 'sort_order' => 1]);
    }

    private function samples(): array
    {
        // Синтетичні розцінки для перевірки ізоляції, не закупівлі власника.
        return [
            'sole-batches' => ['quantity' => 10, 'goods_cny' => 100, 'china_shipping_cny' => 0, 'commission_percent' => 0, 'international_shipping_usd' => 0, 'ukraine_shipping_uah' => 0, 'other_costs_uah' => 0, 'cny_rate' => 6, 'usd_rate' => 40],
            'cardboard-batches' => ['quantity' => 20, 'goods_uah' => 3600, 'shipping_uah' => null, 'sheet_length_cm' => 120, 'sheet_width_cm' => 80, 'blank_length_cm' => 25, 'blank_width_cm' => 9],
            'foam-calculations' => ['sheet_price_usd' => 4.5, 'usd_rate' => 40, 'shipping_uah' => null, 'sheet_length_cm' => 120, 'sheet_width_cm' => 200, 'blank_length_cm' => 25, 'blank_width_cm' => 10],
            'fur-batches' => ['purchase_source' => 'ukraine', 'goods_uah' => 3000, 'ukraine_shipping_uah' => 100, 'other_costs_uah' => 0, 'fabric_length' => 5, 'length_unit' => 'metre', 'fabric_width_cm' => 180, 'cut_length_cm' => 100, 'top_width_cm' => 20, 'bottom_width_cm' => 10, 'height_cm' => 8],
            'laminate-calculations' => ['plush_price_metre_uah' => 120, 'plush_width_cm' => 200, 'web_roll_price_uah' => 800, 'web_roll_length_m' => 40, 'web_width_cm' => 100, 'foam_sheet_price_usd' => 4, 'usd_rate' => 40, 'foam_sheet_length_cm' => 200, 'foam_sheet_width_cm' => 100, 'cut_width_cm' => 100, 'insole_length_cm' => 25, 'insole_width_cm' => 10, 'upper_top_cm' => 20, 'upper_bottom_cm' => 10, 'upper_height_cm' => 10],
            'tape-batches' => ['length_m' => 100, 'goods_uah' => 1000, 'shipping_uah' => 200, 'per_slipper_cm' => 75, 'allowance_cm' => 5],
        ];
    }

    public function test_catalog_and_open_model_are_owner_only(): void
    {
        $category = $this->category();
        $this->getJson(self::URL)->assertUnauthorized();
        $this->postJson(self::URL, ['category_id' => $category->id])->assertUnauthorized();
        foreach ([User::ROLE_OPERATOR, User::ROLE_PACKER] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->getJson(self::URL)->assertForbidden();
            $this->postJson(self::URL, ['category_id' => $category->id])->assertForbidden();
        }
        $this->assertDatabaseCount('production_cost_models', 1);
    }

    public function test_catalog_reads_crm_photos_and_does_not_create_calculations(): void
    {
        $this->owner();
        $category = $this->category();
        Product::create(['title' => 'Тестовий товар', 'category_id' => $category->id, 'main_photo_path' => 'products/example.jpg']);
        $other = $this->category('Тестові інші товари');
        $response = $this->getJson(self::URL)->assertOk()->assertJsonCount(1, 'models')->assertJsonCount(2, 'categories')
            ->assertJsonPath('models.0.records_count', 0)->assertJsonPath('models.0.materials_count', 0);
        // Сортування українських літер різниться між SQLite та MySQL — звіряємо за ID.
        $categories = collect($response->json('categories'))->keyBy('id');
        $this->assertSame('/storage/products/example.jpg', $categories[$category->id]['photo_url']);
        $this->assertTrue($categories[$category->id]['footwear']);
        $this->assertFalse($categories[$other->id]['footwear']);
        $this->assertDatabaseCount('production_cost_models', 1);
        $this->assertDatabaseCount('production_cost_batches', 0);
        $this->assertDatabaseCount('production_cost_batch_revisions', 0);
    }

    public function test_open_category_is_idempotent_and_does_not_copy_halluci_prices(): void
    {
        $this->owner();
        $category = $this->category();
        $id = $this->postJson(self::URL, ['category_id' => $category->id])->assertOk()->json('id');
        $this->postJson(self::URL, ['category_id' => $category->id])->assertOk()->assertJsonPath('id', $id);
        $this->postJson(self::URL, ['category_id' => 99999])->assertUnprocessable();
        $this->assertDatabaseCount('production_cost_models', 2);
        $this->assertDatabaseCount('production_cost_batches', 0);
        $this->getJson('/api/production-costs/cardboard-batches?model_id='.$id)->assertOk()->assertJsonPath('data', []);
        $this->assertDatabaseCount('sole_inventory_batches', 0);
    }

    public function test_every_material_is_isolated_for_reads_writes_and_retries(): void
    {
        $this->owner();
        $model = app(ProductionCostModels::class)->openCategory($this->category()->id)['id'];
        $legacy = app(ProductionCostModels::class)->resolve();
        foreach ($this->samples() as $path => $sample) {
            $url = '/api/production-costs/'.$path;
            $payload = $sample + ['name' => 'Тест', 'request_key' => (string) Str::uuid()];
            $first = $this->postJson($url, $payload)->assertCreated()->assertJsonPath('model_id', $legacy)->json('id');
            $this->getJson($url.'?model_id='.$model)->assertOk()->assertJsonPath('data', []);
            $this->postJson($url, $payload + ['model_id' => $model])->assertConflict();
            $secondPayload = array_replace($payload, ['request_key' => (string) Str::uuid(), 'model_id' => $model]);
            $second = $this->postJson($url, $secondPayload)->assertCreated()->assertJsonPath('model_id', $model)->json('id');
            $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first);
            $this->getJson($url.'?model_id='.$model)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second);
            $edit = $sample + ['name' => 'Зміна', 'version' => 1, 'model_id' => $model];
            $this->putJson($url.'/'.$first, $edit)->assertNotFound();
            $this->putJson($url.'/'.$second, $edit)->assertOk()->assertJsonPath('version', 2);
            unset($edit['model_id']);
            $this->putJson($url.'/'.$second, $edit)->assertNotFound();
            $this->getJson($url.'?model_id=99999')->assertUnprocessable();
            $this->postJson($url, array_replace($payload, ['model_id' => 99999]))->assertUnprocessable();
            $this->getJson($url.'?model_id=')->assertUnprocessable();
        }
        $this->assertDatabaseCount('production_cost_batches', 12);
        $this->assertDatabaseCount('production_cost_batch_revisions', 18);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('models.0.materials_count', 6)->assertJsonPath('models.1.materials_count', 6);
    }

    public function test_category_rename_and_removal_keep_model_and_cost_history(): void
    {
        $this->owner();
        $category = $this->category();
        $model = app(ProductionCostModels::class)->openCategory($category->id)['id'];
        $category->update(['name' => 'Нова назва']);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('models.1.name', 'Нова назва');
        $category->delete();
        $this->getJson(self::URL)->assertOk()->assertJsonPath('models.1.id', $model)->assertJsonPath('models.1.category_id', null);
        $this->getJson('/api/production-costs/sole-batches?model_id='.$model)->assertOk();
    }
}
