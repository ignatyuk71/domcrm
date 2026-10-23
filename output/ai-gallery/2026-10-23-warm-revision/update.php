<?php

declare(strict_types=1);

use App\Models\AiPhoto;
use App\Models\AiPhotoGroup;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/** Вузьке оновлення історичного комплекту: типовий запуск лише перевіряє. */
final class WarmGalleryRevisionError extends RuntimeException {}

const WARM_GALLERY_OLD_NAME = 'Домашні капці з декором';
const WARM_GALLERY_NEW_NAME = 'Жіночі теплі домашні капці';
const WARM_GALLERY_OLD_PREFIX = 'domcrm-decor-20261023-v1-';
const WARM_GALLERY_NEW_PREFIX = 'domcrm-warm-20261023-v2-';
const WARM_GALLERY_LOCK = 'domcrm-decor-20261023-v1-import';
const WARM_GALLERY_SKUS = [
    'kotyky-pink' => '5001', 'kotyky-grey' => '5002',
    'vedmedyky-brown' => '3002', 'vedmedyky-pink' => '3001', 'serdechka-white' => '4000',
];

function warmGalleryRequire(bool $condition, string $message): void
{
    if (!$condition) {
        throw new WarmGalleryRevisionError($message);
    }
}

function warmGalleryIds(array $ids): array
{
    $ids = array_map('intval', $ids);
    sort($ids, SORT_NUMERIC);

    return $ids;
}

/** Перевіряємо локальний файл, MIME, розширення, декодування і SHA-256. */
function warmGallerySource(string $root, mixed $relative): array
{
    warmGalleryRequire(is_string($relative) && $relative !== '' && !str_contains($relative, "\0") && !str_contains($relative, '\\'), 'Некоректний шлях зображення.');
    warmGalleryRequire(!str_starts_with($relative, '/') && !str_contains($relative, ':') && !array_intersect(explode('/', $relative), ['', '.', '..']), 'Зображення має залишатися всередині комплекту.');
    $source = realpath($root.'/'.$relative);
    warmGalleryRequire($source !== false && str_starts_with($source, $root.DIRECTORY_SEPARATOR) && is_file($source) && is_readable($source), 'Зображення відсутнє або виходить за межі комплекту: '.$relative);
    $size = filesize($source);
    warmGalleryRequire(is_int($size) && $size > 0 && $size < 10 * 1024 * 1024, 'Зображення має бути непорожнім і меншим за 10 МБ: '.$relative);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($source);
    $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
    $allowed = ['image/png' => ['png'], 'image/jpeg' => ['jpg', 'jpeg'], 'image/webp' => ['webp']];
    $image = @getimagesize($source);
    warmGalleryRequire(isset($allowed[$mime]) && in_array($extension, $allowed[$mime], true) && $image !== false && $image[0] > 0 && $image[1] > 0 && ($image['mime'] ?? null) === $mime, 'Некоректне зображення або MIME: '.$relative);
    $sha256 = hash_file('sha256', $source);
    warmGalleryRequire(is_string($sha256), 'Не вдалося перевірити SHA-256: '.$relative);

    return compact('source', 'relative', 'size', 'mime', 'extension', 'sha256');
}

/** Старі шляхи виводимо з незмінного v1; нові прив’язуємо до контрольних сум. */
function warmGalleryPlan(string $bundle): array
{
    $historical = realpath(dirname($bundle).'/2026-10-23-combined');
    warmGalleryRequire($historical !== false, 'Історичний комплект v1 відсутній.');
    $old = json_decode((string) file_get_contents($historical.'/gallery.json'), true, 512, JSON_THROW_ON_ERROR);
    $metadata = json_decode((string) file_get_contents($bundle.'/metadata.json'), true, 512, JSON_THROW_ON_ERROR);
    warmGalleryRequire(($metadata['version'] ?? null) === 2 && ($metadata['old_name'] ?? null) === WARM_GALLERY_OLD_NAME && ($metadata['name'] ?? null) === WARM_GALLERY_NEW_NAME, 'Метадані дозволяють лише погоджене перейменування.');
    warmGalleryRequire(($old['groups'][0]['name'] ?? null) === WARM_GALLERY_OLD_NAME && count($old['groups']) === 1, 'Історична група v1 не відповідає комплекту.');
    warmGalleryRequire(($metadata['expected_skus'] ?? null) === array_values(WARM_GALLERY_SKUS) && ($metadata['size_guide_skus'] ?? null) === array_values(WARM_GALLERY_SKUS), 'Склад товарів або область застосування пояснення змінені.');
    $description = $metadata['ai_description'] ?? null;
    warmGalleryRequire(is_string($description) && trim($description) !== '' && mb_strlen($description) <= 3000, 'Опис має містити від 1 до 3000 символів.');
    $cover = warmGallerySource($historical, $old['groups'][0]['cover'] ?? null);
    $oldPlan = [array_merge($cover, ['path' => 'ai-gallery/'.WARM_GALLERY_OLD_PREFIX.'collage.'.$cover['extension'], 'sort_order' => 0, 'product_keys' => array_keys(WARM_GALLERY_SKUS)])];
    $seen = [];
    warmGalleryRequire(is_array($old['products'] ?? null) && count($old['products']) === 5, 'Історичний комплект має містити п’ять товарів.');
    foreach ($old['products'] as $product) {
        $key = $product['key'] ?? null;
        warmGalleryRequire(is_string($key) && isset(WARM_GALLERY_SKUS[$key]) && !isset($seen[$key]) && ($product['sku'] ?? null) === WARM_GALLERY_SKUS[$key] && ($product['group'] ?? null) === 'decor', 'Невідомий, повторений або змінений SKU у v1.');
        warmGalleryRequire(is_array($product['photos'] ?? null) && array_is_list($product['photos']) && count($product['photos']) === 2, 'Кожен SKU v1 повинен мати два оригінали.');
        $seen[$key] = true;
        foreach ($product['photos'] as $index => $photo) {
            $source = warmGallerySource($historical, $photo['file'] ?? null);
            $oldPlan[] = array_merge($source, ['path' => 'ai-gallery/'.WARM_GALLERY_OLD_PREFIX.$key.'-'.sprintf('%02d', $index + 1).'.'.$source['extension'], 'sort_order' => count($oldPlan), 'product_keys' => [$key]]);
        }
    }
    warmGalleryRequire(count($oldPlan) === 11 && count(array_unique(array_column($oldPlan, 'source'))) === 11, 'Історичний комплект має містити 11 різних зображень.');
    $newFiles = [];
    foreach (['collage' => 'collages/warm-home-slippers.png', 'size_guide' => 'guides/teddy-sizing-36-37.png'] as $key => $relative) {
        $entry = $metadata['assets'][$key] ?? [];
        warmGalleryRequire(($entry['file'] ?? null) === $relative, 'Невідоме джерело нового зображення.');
        $file = warmGallerySource($bundle, $relative);
        $path = 'ai-gallery/'.WARM_GALLERY_NEW_PREFIX.($key === 'collage' ? 'collage.png' : 'teddy-sizing-36-37.png');
        warmGalleryRequire(($entry['path'] ?? null) === $path && ($entry['sha256'] ?? null) === $file['sha256'], 'Шлях або SHA-256 нового зображення змінено: '.$relative);
        $newFiles[$key] = array_merge($file, ['path' => $path, 'sort_order' => $key === 'collage' ? 0 : 11, 'product_keys' => array_keys(WARM_GALLERY_SKUS)]);
    }

    return ['metadata' => $metadata, 'old_description' => $old['groups'][0]['description'], 'old_plan' => $oldPlan, 'new_files' => $newFiles];
}

/** Відповідність перевіряємо за SKU, жодних змін у товарах не робимо. */
function warmGalleryProducts(bool $lock): array
{
    $products = [];
    foreach (WARM_GALLERY_SKUS as $key => $sku) {
        $query = Product::query()->where('sku', $sku);
        if ($lock) {
            $query->lockForUpdate();
        }
        $matches = $query->get(['id', 'sku', 'title']);
        warmGalleryRequire($matches->count() === 1, 'SKU '.$sku.' має відповідати рівно одному товару.');
        $products[$key] = ['id' => (int) $matches[0]->id, 'sku' => (string) $matches[0]->sku, 'title' => (string) $matches[0]->title];
    }
    warmGalleryRequire(count(array_unique(array_column($products, 'id'))) === 5, 'Потрібні п’ять різних товарів.');

    return $products;
}

function warmGalleryAttachIds(array $plan, array $products): array
{
    foreach ($plan as &$entry) {
        $entry['product_ids'] = array_map(fn (string $key): int => $products[$key]['id'], $entry['product_keys']);
    }
    unset($entry);

    return $plan;
}

/** Повтор дозволений лише для точного v2; будь-який проміжний чи ручний стан зупиняє запис. */
function warmGalleryState(array $config, array $products, bool $lock): array
{
    $query = AiPhotoGroup::query()->whereIn('name', [WARM_GALLERY_OLD_NAME, WARM_GALLERY_NEW_NAME]);
    if ($lock) {
        $query->lockForUpdate();
    }
    $groups = $query->get();
    warmGalleryRequire($groups->count() === 1, 'Має існувати рівно одна наявна група зі старою або новою назвою; нові групи не створюємо.');
    $group = $groups->first();
    $isNew = $group->name === WARM_GALLERY_NEW_NAME;
    warmGalleryRequire($group->ai_description === ($isNew ? $config['metadata']['ai_description'] : $config['old_description']), 'Опис групи змінено вручну або стан неповний; перезапис заборонений.');
    warmGalleryRequire(warmGalleryIds($group->products()->pluck('products.id')->all()) === warmGalleryIds(array_column($products, 'id')), 'Склад товарів групи відрізняється від п’яти погоджених SKU.');
    $oldPlan = warmGalleryAttachIds($config['old_plan'], $products);
    $newFiles = warmGalleryAttachIds($config['new_files'], $products);
    $expected = $oldPlan;
    if ($isNew) {
        $expected[0] = $newFiles['collage'];
        $expected[] = $newFiles['size_guide'];
    }
    $paths = array_unique(array_merge(array_column($oldPlan, 'path'), array_column($newFiles, 'path')));
    warmGalleryRequire(!AiPhoto::query()->whereIn('path', $paths)->where('ai_photo_group_id', '!=', $group->id)->exists(), 'Зарезервовані шляхи використовує інша група.');
    $photoQuery = $group->photos()->with('products:products.id');
    if ($lock) {
        $photoQuery->lockForUpdate();
    }
    $photos = $photoQuery->get();
    warmGalleryRequire($photos->count() === count($expected), 'Кількість фото змінена; потрібні точні 11 фото v1 або 12 фото v2.');
    foreach ($expected as $entry) {
        $matches = $photos->where('path', $entry['path']);
        warmGalleryRequire($matches->count() === 1, 'Фото відсутнє, дубльоване або додане вручну: '.$entry['path']);
        $photo = $matches->first();
        $purpose = $isNew && $entry['path'] === $newFiles['size_guide']['path'] ? AiPhoto::PURPOSE_SIZE_GUIDE : AiPhoto::PURPOSE_PRODUCT;
        warmGalleryRequire($photo->purpose === $purpose && (int) $photo->sort_order === $entry['sort_order'], 'Призначення або порядок фото змінено вручну: '.$entry['path']);
        warmGalleryRequire(warmGalleryIds($photo->products->pluck('id')->all()) === warmGalleryIds($entry['product_ids']), 'Відмітки товарів на фото змінено: '.$entry['path']);
    }

    return compact('group', 'isNew', 'oldPlan', 'newFiles', 'expected');
}

/** public/ai-gallery може бути shared-посиланням; жоден файл не може бути посиланням. */
function warmGalleryDestinations(string $directory, array $plan, bool $required): void
{
    warmGalleryRequire(is_dir($directory) && ($base = realpath($directory)) !== false, 'Каталог public/ai-gallery відсутній або пошкоджений.');
    foreach ($plan as $entry) {
        $path = $base.'/'.basename($entry['path']);
        warmGalleryRequire(!is_link($path), 'Файл призначення є символічним посиланням: '.$entry['path']);
        if (!file_exists($path)) {
            warmGalleryRequire(!$required, 'Фізичний файл відсутній: '.$entry['path']);
            continue;
        }
        warmGalleryRequire(is_file($path) && is_readable($path) && hash_file('sha256', $path) === $entry['sha256'], 'SHA-256 файлу відрізняється; перезапис заборонений: '.$entry['path']);
    }
}

/** Виключне створення файлу виключає перезапис між перевіркою та копіюванням. */
function warmGalleryCopy(string $directory, array $entry): void
{
    $path = $directory.'/'.basename($entry['path']);
    if (file_exists($path) || is_link($path)) {
        warmGalleryDestinations($directory, [$entry], true);
        return;
    }
    $source = @fopen($entry['source'], 'rb');
    warmGalleryRequire($source !== false, 'Не вдалося відкрити джерело: '.$entry['relative']);
    $target = @fopen($path, 'xb');
    if ($target === false) {
        fclose($source);
        warmGalleryDestinations($directory, [$entry], true);
        return;
    }
    try {
        warmGalleryRequire(stream_copy_to_stream($source, $target) === $entry['size'] && fflush($target) && hash_file('sha256', $path) === $entry['sha256'] && chmod($path, 0644), 'Копіювання або перевірка нового файлу не вдалися.');
    } catch (Throwable $error) {
        fclose($target);
        fclose($source);
        @unlink($path);
        throw $error;
    }
    fclose($target);
    fclose($source);
}

function warmGalleryRun(array $config, bool $apply, string $directory): array
{
    $products = warmGalleryProducts(false);
    $state = warmGalleryState($config, $products, false);
    // Навіть після v2 звіряємо всі старі файли: історичний колаж не видаляємо.
    warmGalleryDestinations($directory, $state['oldPlan'], true);
    warmGalleryDestinations($directory, $state['newFiles'], $state['isNew']);
    if (!$apply) {
        return ['id' => (int) $state['group']->id, 'changed' => false, 'already_applied' => $state['isNew']];
    }
    $driver = DB::connection()->getDriverName();
    warmGalleryRequire(in_array($driver, ['mysql', 'sqlite'], true), 'Запис підтримується лише для MySQL або ізольованого SQLite-тесту.');
    $locked = false;
    try {
        if ($driver === 'mysql') {
            $lock = DB::selectOne('SELECT GET_LOCK(?, 5) AS acquired', [WARM_GALLERY_LOCK]);
            warmGalleryRequire((int) ($lock->acquired ?? 0) === 1, 'Інший імпорт галереї вже працює; повторіть пізніше.');
            $locked = true;
        }

        return DB::transaction(function () use ($config, $products, $directory): array {
            $lockedProducts = warmGalleryProducts(true);
            warmGalleryRequire($lockedProducts === $products, 'Відповідність SKU змінилася під час перевірки.');
            $state = warmGalleryState($config, $lockedProducts, true);
            warmGalleryDestinations($directory, $state['oldPlan'], true);
            warmGalleryDestinations($directory, $state['newFiles'], $state['isNew']);
            if ($state['isNew']) {
                return ['id' => (int) $state['group']->id, 'changed' => false, 'already_applied' => true];
            }
            $destination = realpath($directory);
            warmGalleryRequire($destination !== false && is_writable($destination), 'Каталог public/ai-gallery недоступний для запису.');
            foreach ($state['newFiles'] as $entry) {
                warmGalleryRequire(hash_file('sha256', $entry['source']) === $entry['sha256'], 'Джерело змінилося під час перевірки: '.$entry['relative']);
                warmGalleryCopy($destination, $entry);
            }
            $group = $state['group'];
            $group->update(['name' => WARM_GALLERY_NEW_NAME, 'ai_description' => $config['metadata']['ai_description']]);
            $collage = $group->photos()->where('path', $state['oldPlan'][0]['path'])->sole();
            $collage->update(['path' => $state['newFiles']['collage']['path'], 'phash' => null]);
            $guide = $group->photos()->create(['path' => $state['newFiles']['size_guide']['path'], 'sort_order' => 11, 'purpose' => AiPhoto::PURPOSE_SIZE_GUIDE]);
            $guide->products()->sync($state['newFiles']['size_guide']['product_ids']);
            $verified = warmGalleryState($config, $products, true);
            warmGalleryRequire($verified['isNew'], 'Контроль підсумкового стану не пройдено.');

            return ['id' => (int) $group->id, 'changed' => true, 'already_applied' => false];
        });
    } finally {
        if ($locked) {
            try {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [WARM_GALLERY_LOCK]);
            } catch (Throwable) {
                // MySQL звільнить блокування після закриття CLI-з’єднання.
            }
        }
    }
}

function warmGalleryMain(array $arguments): int
{
    try {
        warmGalleryRequire(PHP_SAPI === 'cli' && ($arguments === [] || $arguments === ['--apply']), 'Запускайте з CLI без параметрів для перевірки або з --apply для запису.');
        $bundle = realpath(__DIR__);
        warmGalleryRequire($bundle !== false, 'Комплект недоступний.');
        $config = warmGalleryPlan($bundle);
        $applicationRoot = dirname(__DIR__, 3);
        require $applicationRoot.'/vendor/autoload.php';
        $app = require $applicationRoot.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $apply = $arguments === ['--apply'];
        $result = warmGalleryRun($config, $apply, public_path('ai-gallery'));
        fwrite(STDOUT, ($apply ? 'Режим: ЗАПИС' : 'Режим: DRY-RUN — без записів').PHP_EOL);
        fwrite(STDOUT, 'Наявна група id '.$result['id'].' → «'.WARM_GALLERY_NEW_NAME.'»; 5 товарів, 1 колаж, 10 оригіналів, 1 пояснення розмірів для всіх п’яти товарів групи.'.PHP_EOL);
        fwrite(STDOUT, $result['changed'] ? "Готово: групу оновлено зі збереженням її id і всіх оригіналів.\n" : ($result['already_applied'] ? "Точний стан v2 вже існує; повтор не вносить змін.\n" : "Перевірку пройдено; --apply оновить наявну групу та додасть одне фото-пояснення.\n"));

        return 0;
    } catch (WarmGalleryRevisionError $error) {
        fwrite(STDERR, 'Оновлення зупинено: '.$error->getMessage().PHP_EOL);
    } catch (JsonException) {
        fwrite(STDERR, "Оновлення зупинено: JSON комплекту некоректний.\n");
    } catch (Throwable $error) {
        // Трасування та параметри середовища можуть містити секрети.
        fwrite(STDERR, 'Оновлення зупинено через технічну помилку ('.get_class($error).'). Зміни БД скасовано; власні скопійовані файли придатні для повтору.'.PHP_EOL);
    }

    return 1;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(warmGalleryMain(array_slice($argv, 1)));
}
