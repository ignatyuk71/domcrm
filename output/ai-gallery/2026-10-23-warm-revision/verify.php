<?php

declare(strict_types=1);

/** Ізольовані сценарії: лише SQLite :memory: та тимчасовий каталог. */
require __DIR__.'/update.php';
require dirname(__DIR__, 3).'/vendor/autoload.php';

use App\Models\AiPhoto;
use App\Models\AiPhotoGroup;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('CACHE_STORE=array');
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
DB::purge('sqlite');

$temporary = sys_get_temp_dir().'/domcrm-warm-verify-'.bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$directory = $temporary.'/ai-gallery';
mkdir($directory, 0700);
$tables = ['products', 'ai_photo_groups', 'ai_photos', 'ai_photo_group_product', 'ai_photo_product'];
$passed = 0;

function warmVerify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Помилка перевірки: '.$message);
    }
}

function warmVerifySnapshot(): array
{
    global $tables;
    $state = [];
    foreach ($tables as $table) {
        $state[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    return $state;
}

function warmVerifyFiles(string $directory): array
{
    $files = [];
    foreach (glob($directory.'/*') as $path) {
        $files[basename($path)] = hash_file('sha256', $path);
    }
    ksort($files);

    return $files;
}

function warmVerifyFixture(array $config, string $directory): void
{
    global $tables;
    Schema::disableForeignKeyConstraints();
    foreach (array_reverse($tables) as $table) {
        Schema::dropIfExists($table);
    }
    Schema::enableForeignKeyConstraints();
    Schema::create('products', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->string('sku');
        $table->decimal('sale_price', 10, 2);
        $table->integer('stock_qty');
        $table->timestamps();
    });
    foreach (['2026_06_11_000001_create_ai_gallery_tables.php', '2026_06_11_150001_add_phash_to_ai_photos.php', '2026_06_11_160001_add_ai_description_to_ai_photo_groups.php'] as $migration) {
        (require dirname(__DIR__, 3).'/database/migrations/'.$migration)->up();
    }
    Schema::table('ai_photos', fn (Blueprint $table) => $table->string('purpose', 32)->default(AiPhoto::PURPOSE_PRODUCT));
    foreach (glob($directory.'/*') as $path) {
        unlink($path);
    }
    foreach (array_values(WARM_GALLERY_SKUS) as $index => $sku) {
        DB::table('products')->insert(['id' => 101 + $index, 'title' => 'Товар '.$sku, 'sku' => $sku, 'sale_price' => 380 + $index, 'stock_qty' => 7 + $index]);
    }
    DB::table('products')->insert(['id' => 999, 'title' => 'Інший товар', 'sku' => 'unrelated', 'sale_price' => 777, 'stock_qty' => 42]);
    DB::table('ai_photo_groups')->insert([
        ['id' => 41, 'name' => WARM_GALLERY_OLD_NAME, 'ai_description' => $config['old_description'], 'sort_order' => 8, 'created_at' => '2026-10-20 12:00:00', 'updated_at' => '2026-10-20 12:00:00'],
        ['id' => 99, 'name' => 'Інша група', 'ai_description' => 'Не змінювати', 'sort_order' => 20, 'created_at' => '2026-10-20 12:00:00', 'updated_at' => '2026-10-20 12:00:00'],
    ]);
    $products = warmGalleryProducts(false);
    AiPhotoGroup::findOrFail(41)->products()->attach(array_column($products, 'id'));
    $plan = warmGalleryAttachIds($config['old_plan'], $products);
    foreach ($plan as $index => $entry) {
        $photo = AiPhoto::create(['ai_photo_group_id' => 41, 'path' => $entry['path'], 'sort_order' => $entry['sort_order'], 'phash' => 'abcdef0123456789', 'purpose' => AiPhoto::PURPOSE_PRODUCT]);
        $photo->products()->attach($entry['product_ids']);
        copy($entry['source'], $directory.'/'.basename($entry['path']));
    }
    AiPhoto::create(['ai_photo_group_id' => 99, 'path' => 'ai-gallery/unrelated.jpg', 'sort_order' => 0, 'phash' => '1234', 'purpose' => AiPhoto::PURPOSE_PRODUCT]);
    file_put_contents($directory.'/unrelated.jpg', 'Не змінювати чужий файл.');
}

function warmVerifyRejection(string $label, array $config, string $directory, callable $modify): void
{
    global $passed;
    warmVerifyFixture($config, $directory);
    $modify();
    $before = warmVerifySnapshot();
    $files = warmVerifyFiles($directory);
    $rejected = false;
    try {
        warmGalleryRun($config, true, $directory);
    } catch (WarmGalleryRevisionError) {
        $rejected = true;
    }
    warmVerify($rejected, $label.' має зупинити оновлення.');
    warmVerify(warmVerifySnapshot() === $before && warmVerifyFiles($directory) === $files, $label.' не має змінювати БД або файли.');
    $passed++;
    fwrite(STDOUT, '✓ '.$label.PHP_EOL);
}

try {
    $config = warmGalleryPlan((string) realpath(__DIR__));
    warmVerifyFixture($config, $directory);
    $before = warmVerifySnapshot();
    $files = warmVerifyFiles($directory);
    $dryRun = warmGalleryRun($config, false, $directory);
    warmVerify($dryRun === ['id' => 41, 'changed' => false, 'already_applied' => false], 'Dry-run має знайти наявну групу без жорсткого ID.');
    warmVerify(warmVerifySnapshot() === $before && warmVerifyFiles($directory) === $files, 'Dry-run не має записувати файли або БД.');
    $passed++;
    fwrite(STDOUT, "✓ Dry-run без жодних записів\n");

    $result = warmGalleryRun($config, true, $directory);
    $after = warmVerifySnapshot();
    $group = AiPhotoGroup::findOrFail(41);
    warmVerify($result['changed'] && $result['id'] === 41 && $group->name === WARM_GALLERY_NEW_NAME && (int) $group->sort_order === 8, 'Група має зберегти ID та порядок.');
    warmVerify($after['products'] === $before['products'] && $after['ai_photo_group_product'] === $before['ai_photo_group_product'], 'SKU, ціни, залишки й склад групи незмінні.');
    warmVerify($group->photos()->count() === 12 && AiPhotoGroup::count() === 2 && $after['ai_photo_groups'][1] === $before['ai_photo_groups'][1], 'Потрібні 12 фото в тій самій групі без змін іншої групи.');
    foreach ($before['ai_photos'] as $row) {
        if ($row['ai_photo_group_id'] !== 41 || $row['sort_order'] !== 0) {
            warmVerify(in_array($row, $after['ai_photos'], true), 'Оригінали та сторонні фото мають зберегти всі поля й ID.');
        }
    }
    $guide = $group->photos()->where('purpose', AiPhoto::PURPOSE_SIZE_GUIDE)->sole();
    warmVerify(warmGalleryIds($guide->products()->pluck('products.id')->all()) === [101, 102, 103, 104, 105], 'Пояснення має бути на всіх п’яти SKU групи.');
    warmVerify($group->photos()->where('sort_order', 0)->sole()->phash === null, 'Застарілий phash колажу має бути очищений.');
    foreach ($files as $name => $hash) {
        warmVerify(hash_file('sha256', $directory.'/'.$name) === $hash, 'Існуючі фізичні файли не можна перезаписувати.');
    }
    $passed++;
    fwrite(STDOUT, "✓ Apply: 12 фото, незмінні ID/товари/оригінали/стороння група\n");

    $afterFiles = warmVerifyFiles($directory);
    $repeat = warmGalleryRun($config, true, $directory);
    warmVerify(!$repeat['changed'] && $repeat['already_applied'] && warmVerifySnapshot() === $after && warmVerifyFiles($directory) === $afterFiles, 'Повторний apply має бути повним no-op.');
    $passed++;
    fwrite(STDOUT, "✓ Повторний apply не змінює навіть timestamps\n");

    warmVerifyRejection('Ручний опис', $config, $directory, fn () => DB::table('ai_photo_groups')->where('id', 41)->update(['ai_description' => 'Ручна правка']));
    warmVerifyRejection('Змінений SKU', $config, $directory, fn () => DB::table('products')->where('sku', '3001')->update(['sku' => 'missing']));
    warmVerifyRejection('Зайве фото', $config, $directory, fn () => AiPhoto::create(['ai_photo_group_id' => 41, 'path' => 'ai-gallery/manual.jpg', 'sort_order' => 12]));
    warmVerifyRejection('Змінений порядок', $config, $directory, fn () => DB::table('ai_photos')->where('ai_photo_group_id', 41)->where('sort_order', 1)->update(['sort_order' => 90]));
    warmVerifyRejection('Змінені відмітки товарів', $config, $directory, fn () => DB::table('ai_photo_product')->where('id', 1)->update(['product_id' => 999]));
    warmVerifyRejection('Змінене призначення оригіналу', $config, $directory, fn () => DB::table('ai_photos')->where('ai_photo_group_id', 41)->where('sort_order', 1)->update(['purpose' => AiPhoto::PURPOSE_SIZE_GUIDE]));
    warmVerifyRejection('Колізія файлу v2 без перезапису', $config, $directory, fn () => file_put_contents($directory.'/'.basename($config['new_files']['collage']['path']), 'Інший файл'));
    warmVerifyRejection('Відсутній файл v1', $config, $directory, fn () => unlink($directory.'/'.basename($config['old_plan'][1]['path'])));
    warmVerifyRejection('Дубльована група', $config, $directory, fn () => AiPhotoGroup::create(['name' => WARM_GALLERY_NEW_NAME, 'ai_description' => $config['metadata']['ai_description']]));
    warmVerifyRejection('Шлях v2 у сторонній групі', $config, $directory, fn () => AiPhoto::create(['ai_photo_group_id' => 99, 'path' => $config['new_files']['collage']['path'], 'sort_order' => 1]));

    warmVerifyFixture($config, $directory);
    warmGalleryRun($config, true, $directory);
    $guide = AiPhoto::where('purpose', AiPhoto::PURPOSE_SIZE_GUIDE)->sole();
    $guide->products()->attach(999);
    $tampered = warmVerifySnapshot();
    $rejected = false;
    try {
        warmGalleryRun($config, true, $directory);
    } catch (WarmGalleryRevisionError) {
        $rejected = true;
    }
    warmVerify($rejected && warmVerifySnapshot() === $tampered, 'Після v2 заборонено приймати ручну зміну відміток пояснення.');
    $passed++;
    fwrite(STDOUT, "✓ Ручна зміна v2 зупиняє повтор\n");

    warmVerifyFixture($config, $directory);
    $before = warmVerifySnapshot();
    AiPhoto::creating(function (AiPhoto $photo): void {
        if ($photo->purpose === AiPhoto::PURPOSE_SIZE_GUIDE) {
            throw new RuntimeException('Штучна помилка запису для перевірки транзакції.');
        }
    });
    $failed = false;
    try {
        warmGalleryRun($config, true, $directory);
    } catch (RuntimeException) {
        $failed = true;
    }
    warmVerify($failed && warmVerifySnapshot() === $before, 'Помилка створення пояснення має відкотити перейменування та зміну шляху колажу.');
    $passed++;
    fwrite(STDOUT, "✓ Помилка посеред apply відкочує всі зміни БД\n");
    fwrite(STDOUT, 'Успішно: '.$passed.' сценаріїв. Робочі БД та public не використовувались.'.PHP_EOL);
} finally {
    foreach (glob($directory.'/*') as $path) {
        unlink($path);
    }
    rmdir($directory);
    rmdir($temporary);
}
