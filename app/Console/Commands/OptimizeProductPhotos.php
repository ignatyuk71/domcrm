<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\ImageOptimizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Разове прожимання фото товарів, залитих ДО появи стискання при завантаженні
 * (оригінали з камери по 1–5 МБ). Оригінал іде в бекап, на місці лишається
 * JPEG ~1200px, шлях у products.main_photo_path оновлюється на .jpg.
 */
class OptimizeProductPhotos extends Command
{
    protected $signature = 'products:optimize-photos
        {--dry-run : Лише показати, що буде зроблено, нічого не міняючи}';

    protected $description = 'Стискає наявні фото товарів (ресайз до 1200px + JPEG), оригінали — у бекап';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $backupDir = storage_path('app/product-photos-backup');
        if (!$dryRun) {
            File::ensureDirectoryExists($backupDir);
        }

        // Один шлях можуть ділити кілька товарів — обробляємо шлях один раз.
        $byPath = Product::query()
            ->whereNotNull('main_photo_path')
            ->where('main_photo_path', '!=', '')
            ->where('main_photo_path', 'not like', 'http%')
            ->get(['id', 'main_photo_path'])
            ->groupBy('main_photo_path');

        $done = 0;
        $skipped = 0;
        $missing = 0;
        $failed = 0;
        $bytesBefore = 0;
        $bytesAfter = 0;

        foreach ($byPath as $path => $products) {
            $fullPath = $this->resolveFullPath($path);
            if (!is_file($fullPath)) {
                $this->warn("ВІДСУТНІЙ: {$path}");
                $missing++;
                continue;
            }

            $size = (int) filesize($fullPath);
            $info = @getimagesize($fullPath);
            $isJpeg = $info && $info[2] === IMAGETYPE_JPEG;
            $maxSide = $info ? max($info[0], $info[1]) : 0;

            // Уже легкий JPEG розумного розміру — не чіпаємо.
            if ($isJpeg && $size <= 400 * 1024 && $maxSide <= ImageOptimizer::MAX_SIDE) {
                $skipped++;
                $bytesBefore += $size;
                $bytesAfter += $size;
                continue;
            }

            $newPath = preg_replace('/\.[^.]+$/', '', $path) . '.jpg';
            $newFullPath = $this->resolveFullPath($newPath);

            if ($dryRun) {
                $this->line(sprintf('[dry-run] %s (%s, %dx%d) -> %s', $path, $this->human($size), $info[0] ?? 0, $info[1] ?? 0, $newPath));
                $done++;
                $bytesBefore += $size;
                continue;
            }

            // Бекап оригіналу (не перетираємо, якщо вже є від попереднього запуску).
            $backupFile = $backupDir . '/' . basename($fullPath);
            if (!is_file($backupFile)) {
                File::copy($fullPath, $backupFile);
            }

            // Тиснемо у тимчасовий файл поруч, щоб не лишити битого файлу при збої.
            $tmp = $newFullPath . '.tmp';
            if (!ImageOptimizer::toJpeg($fullPath, $tmp)) {
                @unlink($tmp);
                $this->error("НЕ ВДАЛОСЯ: {$path}");
                $failed++;
                continue;
            }
            File::move($tmp, $newFullPath);

            if ($newFullPath !== $fullPath) {
                @unlink($fullPath);
            }

            Product::whereIn('id', $products->pluck('id'))
                ->update(['main_photo_path' => $newPath]);

            $newSize = (int) filesize($newFullPath);
            $bytesBefore += $size;
            $bytesAfter += $newSize;
            $done++;
            $this->line(sprintf('%s: %s -> %s', $path, $this->human($size), $this->human($newSize)));
        }

        $this->newLine();
        $this->info(sprintf(
            'Стиснуто: %d, пропущено (вже легкі): %d, відсутні файли: %d, помилки: %d',
            $done,
            $skipped,
            $missing,
            $failed
        ));
        if (!$dryRun && $done > 0) {
            $this->info(sprintf('Обсяг: %s -> %s. Бекап оригіналів: %s', $this->human($bytesBefore), $this->human($bytesAfter), $backupDir));
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Той самий резолв шляху, що й у ProductController@destroy. */
    private function resolveFullPath(string $path): string
    {
        $clean = ltrim($path, '/');

        return str_starts_with($clean, 'storage/')
            ? public_path($clean)
            : public_path('storage/' . $clean);
    }

    private function human(int $bytes): string
    {
        return $bytes >= 1048576
            ? sprintf('%.1fMB', $bytes / 1048576)
            : sprintf('%dKB', (int) round($bytes / 1024));
    }
}
