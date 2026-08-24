<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Стискання фото товарів: при завантаженні (storeMainPhoto) і разовою
 * командою products:optimize-photos. Оригінали з камери по 1–5 МБ
 * показуються в мініатюрах 62px — тому все тиснеться в JPEG ≤1200px.
 */
class ProductPhotoOptimizationTest extends TestCase
{
    use RefreshDatabase;

    /** Створені під час тесту файли — приберемо в tearDown. */
    private array $cleanupFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_uploaded_photo_is_compressed_to_jpeg_max_1200px(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));

        $response = $this->post('/products', [
            'title' => 'Тест-капці',
            'main_photo' => UploadedFile::fake()->image('big.png', 3000, 2000),
        ]);

        $response->assertStatus(201);

        $path = Product::firstOrFail()->main_photo_path;
        $this->assertStringEndsWith('.jpg', $path);

        $fullPath = public_path('storage/' . $path);
        $this->cleanupFiles[] = $fullPath;
        $this->assertFileExists($fullPath);

        [$w, $h, $type] = getimagesize($fullPath);
        $this->assertSame(IMAGETYPE_JPEG, $type);
        $this->assertSame(1200, max($w, $h), 'Довша сторона має бути стиснута до 1200px');
        $this->assertSame(800, min($w, $h), 'Пропорції мають зберегтися (3000x2000 -> 1200x800)');
    }

    public function test_small_photo_is_not_upscaled(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner']));

        $this->post('/products', [
            'title' => 'Тест-мала картинка',
            'main_photo' => UploadedFile::fake()->image('small.png', 800, 600),
        ])->assertStatus(201);

        $path = Product::firstOrFail()->main_photo_path;
        $fullPath = public_path('storage/' . $path);
        $this->cleanupFiles[] = $fullPath;

        [$w, $h, $type] = getimagesize($fullPath);
        $this->assertSame(IMAGETYPE_JPEG, $type);
        $this->assertSame([800, 600], [$w, $h], 'Малі фото не збільшуємо');
    }

    public function test_optimize_command_compresses_renames_and_updates_db(): void
    {
        // Важкий PNG «як з проду» прямо у public/storage/products.
        $dir = public_path('storage/products');
        File::ensureDirectoryExists($dir);
        $name = 'test_cmd_' . uniqid();
        $pngPath = $dir . '/' . $name . '.png';
        $img = imagecreatetruecolor(2400, 1600);
        imagepng($img, $pngPath);
        imagedestroy($img);
        $this->cleanupFiles[] = $pngPath;

        $product = Product::create([
            'title' => 'Товар зі старим фото',
            'main_photo_path' => 'products/' . $name . '.png',
        ]);

        $jpgPath = $dir . '/' . $name . '.jpg';
        $backupPath = storage_path('app/product-photos-backup/' . hash('sha256', 'products/' . $name . '.png') . '-' . $name . '.png');
        $this->cleanupFiles[] = $jpgPath;
        $this->cleanupFiles[] = $backupPath;

        $this->artisan('products:optimize-photos')->assertSuccessful();

        $this->assertFileExists($jpgPath, 'Має зʼявитися стиснутий .jpg');
        $this->assertFileDoesNotExist($pngPath, 'Оригінал прибирається з public');
        $this->assertFileExists($backupPath, 'Оригінал має піти в бекап');

        [$w, $h, $type] = getimagesize($jpgPath);
        $this->assertSame(IMAGETYPE_JPEG, $type);
        $this->assertSame(1200, max($w, $h));

        $this->assertSame('products/' . $name . '.jpg', $product->fresh()->main_photo_path);
    }

    public function test_optimize_command_skips_already_light_jpeg(): void
    {
        $dir = public_path('storage/products');
        File::ensureDirectoryExists($dir);
        $name = 'test_skip_' . uniqid();
        $jpgPath = $dir . '/' . $name . '.jpg';
        $img = imagecreatetruecolor(600, 400);
        imagejpeg($img, $jpgPath, 82);
        imagedestroy($img);
        $this->cleanupFiles[] = $jpgPath;

        Product::create([
            'title' => 'Товар з легким фото',
            'main_photo_path' => 'products/' . $name . '.jpg',
        ]);

        $md5Before = md5_file($jpgPath);
        $this->artisan('products:optimize-photos')->assertSuccessful();

        $this->assertSame($md5Before, md5_file($jpgPath), 'Легкий JPEG не перетискаємо');
    }

    public function test_optimize_command_skips_path_that_would_overwrite_another_photo(): void
    {
        $dir = public_path('storage/products');
        File::ensureDirectoryExists($dir);
        $name = 'test_conflict_' . uniqid();
        $pngPath = $dir . '/' . $name . '.png';
        $jpgPath = $dir . '/' . $name . '.jpg';

        $png = imagecreatetruecolor(2400, 1600);
        imagepng($png, $pngPath);
        imagedestroy($png);

        $jpg = imagecreatetruecolor(600, 400);
        imagejpeg($jpg, $jpgPath, 82);
        imagedestroy($jpg);

        $this->cleanupFiles[] = $pngPath;
        $this->cleanupFiles[] = $jpgPath;

        $pngProduct = Product::create([
            'title' => 'Товар з PNG',
            'main_photo_path' => 'products/' . $name . '.png',
        ]);
        $jpgProduct = Product::create([
            'title' => 'Товар з JPEG',
            'main_photo_path' => 'products/' . $name . '.jpg',
        ]);
        $jpgMd5Before = md5_file($jpgPath);

        $this->artisan('products:optimize-photos')->assertSuccessful();

        $this->assertFileExists($pngPath);
        $this->assertSame($jpgMd5Before, md5_file($jpgPath));
        $this->assertSame('products/' . $name . '.png', $pngProduct->fresh()->main_photo_path);
        $this->assertSame('products/' . $name . '.jpg', $jpgProduct->fresh()->main_photo_path);
    }
}
