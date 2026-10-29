<?php

namespace Tests\Feature\GptSandbox;

use App\Models\Category;
use App\Models\User;
use App\Modules\GptSandbox\Infrastructure\CatalogReader;
use App\Modules\GptSandbox\Models\SandboxChat;
use App\Modules\GptSandbox\Models\SandboxSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportCatalogSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private string $snapshot;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'sqlite') $this->markTestSkipped('Імпорт знімка призначений лише для локальної SQLite.');
        $this->app->bind(CatalogReader::class, fn () => new CatalogReader(true));
        \App\Models\AiChannelSetting::current()->update(['gpt_enabled' => true]);
        Storage::fake('local');
        $this->snapshot = tempnam(sys_get_temp_dir(), 'gpt-catalog-test-');
    }

    protected function tearDown(): void
    {
        if (isset($this->snapshot)) unlink($this->snapshot);
        parent::tearDown();
    }

    private function export(array $overrides = []): void
    {
        $tables = [
            'categories' => [['id' => 7, 'name' => 'Капці', 'sort_order' => 0]],
            'colors' => [],
            'products' => [[
                'id' => 33, 'title' => 'Капці бордові', 'sku' => null, 'currency' => 'UAH',
                'sale_price' => '530.00', 'stock_qty' => 2, 'description' => null,
                'is_active' => true, 'category_id' => 7, 'color_id' => null,
            ]],
            'product_variants' => [], 'ai_photo_groups' => [], 'ai_photos' => [],
            'ai_photo_group_product' => [],
            'ai_photo_product' => [['id' => 1, 'ai_photo_id' => 999, 'product_id' => 33]],
        ];
        file_put_contents($this->snapshot, json_encode([
            'version' => 1, 'exported_at' => '2026-10-28T11:26:13+00:00',
            'tables' => array_replace($tables, $overrides),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function test_import_keeps_gpt_credentials_and_chats_and_labels_snapshot(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
        $setting = SandboxSetting::create(['user_id' => $owner->id, 'api_key' => 'sk-test-import', 'model' => 'gpt-test']);
        $chat = SandboxChat::create(['user_id' => $owner->id]);
        $this->export();
        $this->artisan('gpt-sandbox:import-catalog', ['file' => $this->snapshot])->assertExitCode(0);
        $this->assertSame('sk-test-import', $setting->fresh()->api_key);
        $this->assertNotNull($chat->fresh());
        $this->assertDatabaseHas('products', ['id' => 33, 'sale_price' => '530.00']);
        $this->assertDatabaseCount('ai_photo_product', 0);
        $this->assertDatabaseCount('inbox_messages', 0);
        $overview = app(CatalogReader::class)->overview();
        $this->assertSame('server_snapshot', $overview['source']);
        $this->assertSame('2026-10-28T11:26:13+00:00', $overview['captured_at']);
        $this->assertArrayNotHasKey('database', $overview);
    }

    public function test_import_refuses_existing_catalog_and_production_environment(): void
    {
        $this->export();
        Category::create(['name' => 'Наявна категорія']);
        $this->artisan('gpt-sandbox:import-catalog', ['file' => $this->snapshot])->assertExitCode(1);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseHas('categories', ['name' => 'Наявна категорія']);
        $this->app->instance('env', 'production');
        $this->artisan('gpt-sandbox:import-catalog', ['file' => $this->snapshot])->assertExitCode(1);
        Storage::disk('local')->assertMissing('gpt-sandbox/catalog-source.json');
    }

    public function test_import_rolls_back_on_invalid_relations_and_rejects_private_fields(): void
    {
        $this->export(['product_variants' => [['id' => 1, 'product_id' => 999, 'size' => '38', 'stock_qty' => 1, 'is_active' => true]]]);
        $this->artisan('gpt-sandbox:import-catalog', ['file' => $this->snapshot])->assertExitCode(1);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('categories', 0);
        Storage::disk('local')->assertMissing('gpt-sandbox/catalog-source.json');
        $this->export(['products' => [['id' => 33, 'title' => 'Товар', 'cost_price' => '100.00']]]);
        $this->artisan('gpt-sandbox:import-catalog', ['file' => $this->snapshot])->assertExitCode(1);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_import_keeps_photo_purpose_in_metadata_and_existing_gallery_schema(): void
    {
        $this->export([
            'ai_photo_groups' => [['id' => 2, 'name' => 'Група', 'sort_order' => 0, 'ai_description' => null]],
            'ai_photos' => [['id' => 15, 'ai_photo_group_id' => 2, 'path' => 'ai-gallery/sizing.png', 'sort_order' => 0, 'purpose' => 'size_guide']],
        ]);
        $this->artisan('gpt-sandbox:import-catalog', ['file' => $this->snapshot])->assertExitCode(0);
        $this->assertTrue(Schema::hasColumn('ai_photos', 'purpose'));
        $source = json_decode(Storage::disk('local')->get('gpt-sandbox/catalog-source.json'), true);
        $this->assertSame(['path' => 'ai-gallery/sizing.png', 'purpose' => 'size_guide'], $source['photo_purposes'][15]);
        $this->assertDatabaseHas('ai_photos', ['id' => 15, 'path' => 'ai-gallery/sizing.png']);
    }

    public function test_metadata_only_import_preserves_catalog_timestamp_and_gpt_history(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
        $setting = SandboxSetting::create(['user_id' => $owner->id, 'api_key' => 'sk-test-purpose', 'model' => 'gpt-test']);
        $chat = SandboxChat::create(['user_id' => $owner->id]);
        $this->export([
            'ai_photo_groups' => [['id' => 2, 'name' => 'Група', 'sort_order' => 0, 'ai_description' => null]],
            'ai_photos' => [['id' => 15, 'ai_photo_group_id' => 2, 'path' => 'ai-gallery/sizing.png', 'sort_order' => 0]],
        ]);
        $this->artisan('gpt-sandbox:import-catalog', ['file' => $this->snapshot])->assertExitCode(0);
        $before = DB::table('products')->first();
        $photoBefore = DB::table('ai_photos')->first();
        $source = json_decode(Storage::disk('local')->get('gpt-sandbox/catalog-source.json'), true);
        file_put_contents($this->snapshot, json_encode([
            'version' => 1, 'exported_at' => '2026-10-28T20:00:00+00:00',
            'tables' => ['ai_photos' => [['id' => 15, 'path' => 'ai-gallery/sizing.png', 'purpose' => 'size_guide']]],
        ]));
        $this->artisan('gpt-sandbox:import-catalog', ['file' => $this->snapshot, '--photo-purposes-only' => true])->assertExitCode(0);
        $updated = json_decode(Storage::disk('local')->get('gpt-sandbox/catalog-source.json'), true);
        $this->assertSame($source['captured_at'], $updated['captured_at']);
        $this->assertSame('2026-10-28T20:00:00+00:00', $updated['photo_purposes_captured_at']);
        $this->assertSame('size_guide', $updated['photo_purposes'][15]['purpose']);
        $this->assertEquals($before, DB::table('products')->first());
        $this->assertEquals($photoBefore, DB::table('ai_photos')->first());
        $this->assertSame('sk-test-purpose', $setting->fresh()->api_key);
        $this->assertNotNull($chat->fresh());
        $this->assertDatabaseCount('inbox_messages', 0);

        // Невідповідність шляху не повинна частково перезаписувати метадані.
        $export = json_decode(file_get_contents($this->snapshot), true);
        $export['tables']['ai_photos'][0]['path'] = 'ai-gallery/another.png';
        file_put_contents($this->snapshot, json_encode($export));
        $this->artisan('gpt-sandbox:import-catalog', ['file' => $this->snapshot, '--photo-purposes-only' => true])->assertExitCode(1);
        $this->assertSame($updated, json_decode(Storage::disk('local')->get('gpt-sandbox/catalog-source.json'), true));
    }
}
