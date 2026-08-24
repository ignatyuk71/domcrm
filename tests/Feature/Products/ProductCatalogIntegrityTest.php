<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ProductCatalogIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private array $cleanupFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_deleting_product_keeps_photo_used_by_another_product(): void
    {
        $path = 'products/shared_' . uniqid() . '.jpg';
        $fullPath = public_path('storage/' . $path);
        File::ensureDirectoryExists(dirname($fullPath));

        $image = imagecreatetruecolor(100, 100);
        imagejpeg($image, $fullPath, 82);
        imagedestroy($image);
        $this->cleanupFiles[] = $fullPath;

        $deleted = Product::create(['title' => 'Товар для видалення', 'main_photo_path' => $path]);
        $kept = Product::create(['title' => 'Товар зі спільним фото', 'main_photo_path' => $path]);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]))
            ->deleteJson("/products/{$deleted->id}")
            ->assertOk();

        $this->assertFileExists($fullPath);
        $this->assertDatabaseHas('products', ['id' => $kept->id, 'main_photo_path' => $path]);
    }

    public function test_removing_last_variant_resets_parent_stock_to_zero(): void
    {
        $product = Product::create(['title' => 'Товар з варіантом', 'stock_qty' => 6]);
        ProductVariant::create([
            'product_id' => $product->id,
            'size' => '38',
            'stock_qty' => 6,
            'is_active' => true,
        ]);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]))
            ->putJson("/products/{$product->id}", [
                'title' => $product->title,
                'stock_qty' => 6,
                'variants' => [],
            ])
            ->assertOk();

        $this->assertDatabaseCount('product_variants', 0);
        $this->assertSame(0, $product->fresh()->stock_qty);
    }

    public function test_manual_stock_is_preserved_when_product_has_no_variants(): void
    {
        $product = Product::create(['title' => 'Товар без варіантів', 'stock_qty' => 7]);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]))
            ->putJson("/products/{$product->id}", [
                'title' => $product->title,
                'stock_qty' => 7,
                'variants' => [],
            ])
            ->assertOk();

        $this->assertSame(7, $product->fresh()->stock_qty);
    }
}
