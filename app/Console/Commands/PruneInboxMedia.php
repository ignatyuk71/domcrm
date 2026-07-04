<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Чистка старих медіа переписок: докачані файли (inbox-media) і картинки
 * контексту сторіс/постів (inbox-context) старші за N днів видаляються,
 * щоб теки не росли вічно. Тексти переписок не чіпаються; биті картинки
 * фронт ховає (onerror), тож старий чат просто показується без фото.
 */
class PruneInboxMedia extends Command
{
    protected $signature = 'inbox:prune-media {--days=90 : Видаляти файли, старші за стільки днів (мінімум 30)}';

    protected $description = 'Чистка старих медіа переписок (public/inbox-media, public/inbox-context)';

    public function handle(): int
    {
        // Запобіжник: --days=0 чи від'ємне значення не сміє знести всі файли.
        $days = max(30, (int) $this->option('days'));
        $cutoff = now()->subDays($days)->getTimestamp();

        $deleted = 0;
        $kept = 0;
        $bytes = 0;

        foreach (['inbox-media', 'inbox-context'] as $dir) {
            $path = public_path($dir);
            if (!is_dir($path)) {
                continue;
            }
            foreach (new \DirectoryIterator($path) as $file) {
                if (!$file->isFile() || $file->getFilename() === '.gitignore') {
                    continue;
                }
                if ($file->getMTime() >= $cutoff) {
                    $kept++;
                    continue;
                }
                $bytes += $file->getSize();
                @unlink($file->getPathname());
                $deleted++;
            }
        }

        $this->info(sprintf(
            'Видалено: %d файлів (%.1f МБ), лишилось: %d (поріг — %d днів).',
            $deleted, $bytes / 1048576, $kept, $days
        ));

        return self::SUCCESS;
    }
}