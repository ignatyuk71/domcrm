<?php

declare(strict_types=1);

use App\Models\AiPhoto;
use App\Models\AiPhotoGroup;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/** Вузький імпорт цього комплекту; типовий запуск нічого не записує. */
final class DecorGalleryImportError extends RuntimeException {}

const DECOR_IMPORT_NAME = 'Домашні капці з декором';
const DECOR_IMPORT_PREFIX = 'domcrm-decor-20261023-v1-';
const DECOR_IMPORT_SKUS = [
    'kotyky-pink' => '5001',
    'kotyky-grey' => '5002',
    'vedmedyky-brown' => '3002',
    'vedmedyky-pink' => '3001',
    'serdechka-white' => '4000',
];

function decorImportRequire(bool $condition, string $message): void
{
    if (!$condition) {
        throw new DecorGalleryImportError($message);
    }
}

/** realpath перевіряє також символічні посилання всередині комплекту. */
function decorImportSource(string $root, mixed $relative): array
{
    decorImportRequire(is_string($relative) && $relative !== '', 'Не задано шлях до фото.');
    decorImportRequire(!str_contains($relative, "\0") && !str_contains($relative, '\\'), 'Некоректний шлях до фото.');
    decorImportRequire(!str_starts_with($relative, '/') && !str_contains($relative, ':'), 'Фото має бути локальним файлом комплекту.');
    decorImportRequire(!array_intersect(explode('/', $relative), ['', '.', '..']), 'Вихід за межі комплекту заборонений.');
    $absolute = realpath($root.'/'.$relative);
    decorImportRequire($absolute !== false && str_starts_with($absolute, $root.DIRECTORY_SEPARATOR), 'Фото відсутнє або виходить за межі комплекту: '.$relative);
    decorImportRequire(is_file($absolute) && is_readable($absolute), 'Фото недоступне для читання: '.$relative);
    $size = filesize($absolute);
    decorImportRequire($size !== false && $size > 0 && $size < 10 * 1024 * 1024, 'Фото має бути непорожнім і меншим за 10 МБ: '.$relative);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($absolute);
    $extensions = ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp']];
    $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
    decorImportRequire(isset($extensions[$mime]) && in_array($extension, $extensions[$mime], true), 'MIME та розширення фото не узгоджені: '.$relative);
    $image = @getimagesize($absolute);
    decorImportRequire($image !== false && $image[0] > 0 && $image[1] > 0 && ($image['mime'] ?? '') === $mime, 'Файл не є коректним зображенням: '.$relative);
    $sha256 = hash_file('sha256', $absolute);
    decorImportRequire(is_string($sha256), 'Не вдалося перевірити SHA-256 фото: '.$relative);

    return ['source' => $absolute, 'relative' => $relative, 'size' => $size, 'sha256' => $sha256, 'extension' => $extension];
}

function decorImportIds(array $ids): array
{
    $ids = array_map('intval', $ids);
    sort($ids, SORT_NUMERIC);

    return $ids;
}

/** SKU є єдиним джерелом відповідності; назви, ціни й залишки не змінюємо. */
function decorImportProducts(array $products, bool $lock = false): array
{
    $result = [];
    foreach ($products as $entry) {
        $query = Product::query()->where('sku', $entry['sku']);
        if ($lock) {
            $query->lockForUpdate();
        }
        $matches = $query->get(['id', 'sku', 'title']);
        decorImportRequire($matches->count() === 1, 'SKU '.$entry['sku'].' має відповідати рівно одному товару; знайдено: '.$matches->count().'.');
        $result[$entry['key']] = ['id' => (int) $matches[0]->id, 'sku' => (string) $matches[0]->sku, 'title' => $matches[0]->title];
    }
    decorImportRequire(count(array_unique(array_column($result, 'id'))) === 5, 'П’ять SKU мають відповідати п’ятьом різним товарам.');

    return $result;
}

/** Дозволяємо повтор лише для повністю незміненого результату цього імпорту. */
function decorImportExisting(string $description, array $plan, array $productIds, bool $lock = false): ?AiPhotoGroup
{
    $query = AiPhotoGroup::query()->where('name', DECOR_IMPORT_NAME);
    if ($lock) {
        $query->lockForUpdate();
    }
    $groups = $query->get();
    decorImportRequire($groups->count() <= 1, 'Існує кілька груп з такою назвою; автоматичний імпорт зупинено.');
    $group = $groups->first();
    $paths = array_column($plan, 'path');
    $foreign = AiPhoto::query()->whereIn('path', $paths);
    if ($group !== null) {
        $foreign->where('ai_photo_group_id', '!=', $group->id);
    }
    decorImportRequire(!$foreign->exists(), 'Зарезервовані шляхи фото вже використовує інша група.');
    if ($group === null) {
        return null;
    }

    decorImportRequire($group->name === DECOR_IMPORT_NAME && $group->ai_description === $description, 'Існуюча група має інший опис або ручні правки; перезапис заборонений.');
    decorImportRequire($group->getRawOriginal('created_at') === $group->getRawOriginal('updated_at'), 'Існуючу групу редагували після створення; перезапис заборонений.');
    $actualIds = $group->products()->pluck('products.id')->all();
    decorImportRequire(decorImportIds($actualIds) === decorImportIds($productIds), 'Склад товарів існуючої групи відрізняється від комплекту; перезапис заборонений.');
    $photos = $group->photos()->with('products:products.id')->get();
    decorImportRequire($photos->count() === count($plan), 'Кількість фото існуючої групи змінена; перезапис заборонений.');
    foreach ($plan as $entry) {
        $matches = $photos->where('path', $entry['path']);
        decorImportRequire($matches->count() === 1, 'У групі є сторонні, дубльовані або відсутні фото; перезапис заборонений.');
        $photo = $matches->first();
        decorImportRequire((int) $photo->sort_order === $entry['sort_order'], 'Порядок фото змінено вручну; перезапис заборонений.');
        decorImportRequire(decorImportIds($photo->products->pluck('id')->all()) === decorImportIds($entry['product_ids']), 'Відмітки товарів на фото змінено; перезапис заборонений.');
    }

    return $group;
}

/** ai-gallery у продакшені може бути посиланням на shared; самі файли — ні. */
function decorImportDestinations(string $directory, array $plan, bool $requireExisting): void
{
    decorImportRequire(!is_link($directory) || is_dir($directory), 'Посилання public/ai-gallery пошкоджене.');
    decorImportRequire(!file_exists($directory) || is_dir($directory), 'public/ai-gallery не є каталогом.');
    $base = is_dir($directory) ? realpath($directory) : false;
    decorImportRequire(!is_dir($directory) || $base !== false, 'Неможливо визначити каталог public/ai-gallery.');
    foreach ($plan as $entry) {
        $path = ($base !== false ? $base : $directory).'/'.basename($entry['path']);
        decorImportRequire(!is_link($path), 'Файл призначення є символічним посиланням: '.$entry['path']);
        if (!file_exists($path)) {
            decorImportRequire(!$requireExisting, 'У наявної групи відсутній фізичний файл: '.$entry['path']);
            continue;
        }
        decorImportRequire(is_file($path) && is_readable($path), 'Файл призначення недоступний: '.$entry['path']);
        decorImportRequire(hash_file('sha256', $path) === $entry['sha256'], 'Файл призначення має інший SHA-256; перезапис заборонений: '.$entry['path']);
    }
}

/** Режим xb не дозволяє перезаписати файл навіть між перевіркою та копіюванням. */
function decorImportCopy(string $directory, array $entry): void
{
    $path = $directory.'/'.basename($entry['path']);
    if (file_exists($path) || is_link($path)) {
        decorImportDestinations($directory, [$entry], true);
        return;
    }
    $source = @fopen($entry['source'], 'rb');
    decorImportRequire($source !== false, 'Не вдалося відкрити джерело: '.$entry['relative']);
    $target = @fopen($path, 'xb');
    if ($target === false) {
        fclose($source);
        decorImportDestinations($directory, [$entry], true);
        return;
    }
    try {
        $bytes = stream_copy_to_stream($source, $target);
        decorImportRequire($bytes === $entry['size'] && fflush($target), 'Не вдалося повністю скопіювати фото: '.$entry['relative']);
        decorImportRequire(hash_file('sha256', $path) === $entry['sha256'], 'SHA-256 копії не збігається: '.$entry['relative']);
        decorImportRequire(chmod($path, 0644), 'Не вдалося встановити права нового фото: '.$entry['relative']);
    } catch (Throwable $error) {
        // Прибираємо лише щойно створену неповну копію; чужі файли не чіпаємо.
        fclose($target);
        fclose($source);
        @unlink($path);
        throw $error;
    }
    fclose($target);
    fclose($source);
}

$databaseLock = false;

try {
    decorImportRequire(PHP_SAPI === 'cli', 'Цей імпорт дозволений лише з CLI.');
    $arguments = array_slice($argv, 1);
    decorImportRequire($arguments === [] || $arguments === ['--apply'], 'Запускайте без параметрів для перевірки або з --apply для запису.');
    $apply = $arguments === ['--apply'];
    $bundle = realpath(__DIR__);
    decorImportRequire($bundle !== false, 'Каталог комплекту недоступний.');
    $manifestPath = realpath($bundle.'/gallery.json');
    decorImportRequire($manifestPath !== false && str_starts_with($manifestPath, $bundle.DIRECTORY_SEPARATOR) && is_readable($manifestPath), 'gallery.json недоступний або виходить за межі комплекту.');
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    decorImportRequire(is_array($manifest) && isset($manifest['groups'], $manifest['products']) && is_array($manifest['groups']) && is_array($manifest['products']), 'Некоректна структура gallery.json.');
    decorImportRequire(array_is_list($manifest['groups']) && count($manifest['groups']) === 1, 'Комплект повинен містити рівно одну групу.');
    decorImportRequire(array_is_list($manifest['products']) && count($manifest['products']) === 5, 'Комплект повинен містити рівно п’ять товарів.');
    $groupData = $manifest['groups'][0];
    decorImportRequire(is_array($groupData) && ($groupData['key'] ?? null) === 'decor' && ($groupData['name'] ?? null) === DECOR_IMPORT_NAME, 'Дозволено лише групу «'.DECOR_IMPORT_NAME.'».');
    $description = $groupData['description'] ?? null;
    decorImportRequire(is_string($description) && trim($description) !== '' && mb_strlen($description) <= 3000, 'Опис групи має містити від 1 до 3000 символів.');
    $cover = decorImportSource($bundle, $groupData['cover'] ?? null);
    $plan = [array_merge($cover, ['path' => 'ai-gallery/'.DECOR_IMPORT_PREFIX.'collage.'.$cover['extension'], 'sort_order' => 0, 'product_keys' => array_keys(DECOR_IMPORT_SKUS)])];
    $seen = [];
    foreach ($manifest['products'] as $entry) {
        decorImportRequire(is_array($entry), 'Некоректний запис товару.');
        $key = $entry['key'] ?? null;
        decorImportRequire(is_string($key) && isset(DECOR_IMPORT_SKUS[$key]) && !isset($seen[$key]), 'Невідомий або повторений ключ товару.');
        decorImportRequire(isset($entry['sku']) && (string) $entry['sku'] === DECOR_IMPORT_SKUS[$key], 'Некоректний SKU товару '.$key.'.');
        decorImportRequire(($entry['group'] ?? null) === 'decor', 'Товар '.$key.' має належати групі decor.');
        decorImportRequire(isset($entry['photos']) && is_array($entry['photos']) && array_is_list($entry['photos']) && count($entry['photos']) === 2, 'Товар '.$key.' повинен мати рівно два фото.');
        $seen[$key] = true;
        foreach ($entry['photos'] as $index => $photo) {
            decorImportRequire(is_array($photo), 'Некоректний запис фото товару '.$key.'.');
            $source = decorImportSource($bundle, $photo['file'] ?? null);
            $plan[] = array_merge($source, ['path' => 'ai-gallery/'.DECOR_IMPORT_PREFIX.$key.'-'.sprintf('%02d', $index + 1).'.'.$source['extension'], 'sort_order' => count($plan), 'product_keys' => [$key]]);
        }
    }
    decorImportRequire(count($plan) === 11 && count(array_unique(array_column($plan, 'source'))) === 11, 'Потрібні десять різних оригіналів та один окремий колаж.');

    // Laravel завантажуємо лише після перевірки всього локального комплекту.
    $applicationRoot = dirname(__DIR__, 3);
    decorImportRequire(is_file($applicationRoot.'/vendor/autoload.php') && is_file($applicationRoot.'/bootstrap/app.php'), 'Laravel або vendor/autoload.php недоступні.');
    require $applicationRoot.'/vendor/autoload.php';
    $app = require $applicationRoot.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $resolved = decorImportProducts($manifest['products']);
    $productIds = array_column($resolved, 'id');
    foreach ($plan as &$entry) {
        $entry['product_ids'] = array_map(fn (string $key): int => $resolved[$key]['id'], $entry['product_keys']);
    }
    unset($entry);
    $directory = public_path('ai-gallery');
    $existing = decorImportExisting($description, $plan, $productIds);
    decorImportDestinations($directory, $plan, $existing !== null);
    fwrite(STDOUT, ($apply ? 'Режим: ЗАПИС' : 'Режим: DRY-RUN — без записів').PHP_EOL);
    fwrite(STDOUT, 'Група: '.DECOR_IMPORT_NAME.'; товарів: 5; колажів: 1; окремих фото: 10.'.PHP_EOL);
    foreach ($resolved as $product) {
        fwrite(STDOUT, 'SKU '.$product['sku'].' → id '.$product['id'].' — '.$product['title'].PHP_EOL);
    }
    if (!$apply) {
        fwrite(STDOUT, $existing === null ? "Перевірку пройдено. Буде створено одну групу та 11 фото.\n" : "Перевірку пройдено. Комплект уже імпортований; повтор не внесе змін.\n");
        exit(0);
    }

    // MySQL не має унікальності назви групи: серіалізуємо повторні CLI-запуски.
    $driver = DB::connection()->getDriverName();
    decorImportRequire(in_array($driver, ['mysql', 'sqlite'], true), 'Режим запису підтримує лише MySQL та SQLite.');
    if ($driver === 'mysql') {
        $lock = DB::selectOne('SELECT GET_LOCK(?, 5) AS acquired', [DECOR_IMPORT_PREFIX.'import']);
        decorImportRequire((int) ($lock->acquired ?? 0) === 1, 'Інший імпорт уже працює; повторіть після його завершення.');
        $databaseLock = true;
    }
    $result = DB::transaction(function () use ($manifest, $description, $plan, $productIds, $directory, $resolved): array {
        $lockedProducts = decorImportProducts($manifest['products'], true);
        decorImportRequire($lockedProducts === $resolved, 'Відповідність SKU змінилася під час перевірки; запустіть імпорт повторно.');
        $existing = decorImportExisting($description, $plan, $productIds, true);
        decorImportDestinations($directory, $plan, $existing !== null);
        if ($existing !== null) {
            return ['id' => $existing->id, 'created' => false];
        }
        if (!is_dir($directory)) {
            decorImportRequire(@mkdir($directory, 0755, true) || is_dir($directory), 'Неможливо створити public/ai-gallery.');
        }
        $destination = realpath($directory);
        decorImportRequire($destination !== false && is_writable($destination), 'Каталог public/ai-gallery недоступний для запису.');
        foreach ($plan as $entry) {
            decorImportRequire(hash_file('sha256', $entry['source']) === $entry['sha256'], 'Джерело фото змінилося під час перевірки: '.$entry['relative']);
            decorImportCopy($destination, $entry);
        }
        $group = AiPhotoGroup::create(['name' => DECOR_IMPORT_NAME, 'ai_description' => $description, 'sort_order' => (int) AiPhotoGroup::max('sort_order') + 1]);
        $group->products()->syncWithoutDetaching($productIds);
        foreach ($plan as $entry) {
            $photo = AiPhoto::firstOrCreate(['ai_photo_group_id' => $group->id, 'path' => $entry['path']], ['sort_order' => $entry['sort_order']]);
            $photo->products()->syncWithoutDetaching($entry['product_ids']);
        }
        decorImportExisting($description, $plan, $productIds, true);

        return ['id' => $group->id, 'created' => true];
    });
    fwrite(STDOUT, $result['created'] ? 'Готово: створено групу id '.$result['id'].' та 11 розмічених фото.'.PHP_EOL : 'Без змін: група id '.$result['id'].' уже містить цей комплект.'.PHP_EOL);
} catch (DecorGalleryImportError $error) {
    fwrite(STDERR, 'Імпорт зупинено: '.$error->getMessage().PHP_EOL);
    exit(1);
} catch (JsonException $error) {
    fwrite(STDERR, "Імпорт зупинено: gallery.json містить некоректний JSON.\n");
    exit(1);
} catch (Throwable $error) {
    // Не виводимо трасування, параметри з’єднання чи інші секрети середовища.
    fwrite(STDERR, 'Імпорт зупинено через технічну помилку ('.get_class($error).'). Зміни БД скасовано; власні вже скопійовані файли можна використати для повтору.'.PHP_EOL);
    exit(1);
} finally {
    if ($databaseLock) {
        try {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [DECOR_IMPORT_PREFIX.'import']);
        } catch (Throwable) {
            // MySQL також звільнить іменований lock при закритті з’єднання CLI.
        }
    }
}
