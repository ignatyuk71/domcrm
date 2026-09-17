<?php

namespace Tests\Feature\Analytics;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Costs\ProductionCostModels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $this->getJson('/api/production-costs/summary')->assertUnauthorized();
        $this->postJson(self::URL, ['category_id' => $category->id])->assertUnauthorized();
        foreach ([User::ROLE_OPERATOR, User::ROLE_PACKER] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->getJson(self::URL)->assertForbidden();
            $this->getJson('/api/production-costs/summary')->assertForbidden();
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

    public function test_summary_uses_one_latest_batch_per_component_and_never_writes_or_mixes_models(): void
    {
        $this->owner();
        $legacy = app(ProductionCostModels::class)->resolve();
        $other = app(ProductionCostModels::class)->openCategory($this->category()->id)['id'];
        $components = ['sole-batches' => 'soles', 'cardboard-batches' => 'cardboard', 'foam-calculations' => 'foam', 'fur-batches' => 'fur', 'laminate-calculations' => 'laminate', 'tape-batches' => 'tape'];
        $expected = [];
        foreach ($this->samples() as $path => $sample) {
            foreach (['Попередня', 'Остання'] as $name) {
                $row = $this->postJson('/api/production-costs/'.$path, $sample + ['name' => $name, 'request_key' => (string) Str::uuid()])->assertCreated()->json();
            }
            $expected[$components[$path]] = $row;
        }
        $this->postJson('/api/production-costs/tape-batches', $this->samples()['tape-batches'] + ['name' => 'Інша категорія', 'request_key' => (string) Str::uuid(), 'model_id' => $other])->assertCreated();
        $before = DB::table('production_cost_batches')->orderBy('id')->get()->toJson();
        $revisions = DB::table('production_cost_batch_revisions')->count();
        $response = $this->getJson('/api/production-costs/summary?model_id='.$legacy)->assertOk()->assertJsonPath('model_id', $legacy)->assertJsonCount(6, 'components');
        foreach ($expected as $component => $row) {
            $response->assertJsonPath('components.'.$component.'.id', $row['id'])->assertJsonPath('components.'.$component.'.calculation', $row['calculation']);
        }
        $response->assertJsonPath('components.laminate.calculation.unit_cost_uah', null);
        $this->assertGreaterThan(0, $response->json('components.laminate.calculation.insole_pair_cost_uah'));
        $this->assertGreaterThan(0, $response->json('components.laminate.calculation.upper_pair_cost_uah'));
        $this->getJson('/api/production-costs/summary')->assertOk()->assertJsonCount(6, 'components');
        $this->getJson('/api/production-costs/summary?model_id='.$other)->assertOk()->assertJsonCount(1, 'components')->assertJsonPath('components.tape.name', 'Інша категорія');
        $this->getJson('/api/production-costs/summary?model_id=99999')->assertUnprocessable();
        $this->getJson('/api/production-costs/summary?model_id=')->assertUnprocessable();
        $this->assertSame($before, DB::table('production_cost_batches')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('production_cost_batch_revisions', $revisions);
    }

    public function test_empty_summary_does_not_fabricate_prices(): void
    {
        $this->owner();
        $this->getJson('/api/production-costs/summary')->assertOk()->assertJsonCount(0, 'components');
        $this->assertDatabaseCount('production_cost_batches', 0);
    }

    public function test_outdoor_profile_has_only_base_and_fur_and_survives_category_rename(): void
    {
        $this->owner();
        $category = $this->category('Капці для вулиці (хутряні)');
        $id = $this->postJson(self::URL, ['category_id' => $category->id])->assertOk()->assertJsonPath('cost_profile', 'outdoor')->json('id');
        $payload = $this->samples()['sole-batches'] + ['name' => 'Тестовий комплект', 'model_id' => $id, 'upper_shipping_usd' => 20, 'request_key' => (string) Str::uuid()];
        $row = $this->postJson('/api/production-costs/sole-batches', $payload)->assertCreated()
            ->assertJsonPath('inputs.upper_shipping_usd', '20.00')->assertJsonPath('calculation.total_uah', 1400)
            ->assertJsonPath('calculation.breakdown.0.label', 'Підошва + верх')->json();
        $this->postJson('/api/production-costs/sole-batches', $payload)->assertCreated()->assertJsonPath('id', $row['id']);
        $this->getJson('/api/production-costs/summary?model_id='.$id)->assertOk()->assertJsonPath('cost_profile', 'outdoor')->assertJsonCount(1, 'components');
        foreach (['cardboard-batches', 'foam-calculations', 'laminate-calculations', 'tape-batches'] as $path) {
            $this->getJson('/api/production-costs/'.$path.'?model_id='.$id)->assertNotFound();
            $this->postJson('/api/production-costs/'.$path, $this->samples()[$path] + ['name' => 'Зайвий матеріал', 'model_id' => $id, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        }
        foreach ([-1, '', null, '1.001', '1000001'] as $invalid) {
            $this->postJson('/api/production-costs/sole-batches', array_replace($payload, ['request_key' => (string) Str::uuid(), 'upper_shipping_usd' => $invalid]))->assertUnprocessable();
        }
        $category->update(['name' => 'Перейменована категорія']);
        $this->postJson(self::URL, ['category_id' => $category->id])->assertOk()->assertJsonPath('cost_profile', 'outdoor');
        $this->assertDatabaseCount('production_cost_batches', 1);
        $this->assertDatabaseCount('sole_inventory_batches', 0);
    }

    public function test_profile_summary_ignores_old_inapplicable_materials_without_deleting_them(): void
    {
        $this->owner();
        $id = app(ProductionCostModels::class)->openCategory($this->category()->id)['id'];
        $this->postJson('/api/production-costs/cardboard-batches', $this->samples()['cardboard-batches'] + ['name' => 'Попередній розрахунок', 'model_id' => $id, 'request_key' => (string) Str::uuid()])->assertCreated();
        DB::table('production_cost_models')->where('id', $id)->update(['cost_profile' => 'outdoor']);
        $this->getJson('/api/production-costs/summary?model_id='.$id)->assertOk()->assertJsonCount(0, 'components');
        $this->assertDatabaseCount('production_cost_batches', 1);
        $this->assertDatabaseCount('production_cost_batch_revisions', 1);
        $this->postJson('/api/production-costs/sole-batches', $this->samples()['sole-batches'] + ['name' => 'Чужа доставка', 'upper_shipping_usd' => 10, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
    }
}
