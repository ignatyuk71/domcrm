<?php

namespace App\Modules\GptSandbox\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** Одноразове наповнення порожньої локальної тестової бази копією лише каталогу. */
class ImportCatalogSnapshot extends Command
{
    protected $signature = 'gpt-sandbox:import-catalog {file : JSON-експорт каталогу із сервера}
        {--photo-purposes-only : Оновити лише призначення фото в метаданих локальної GPT-копії}';

    protected $description = 'Завантажити каталог у порожню локальну SQLite, не змінюючи GPT-чати';

    private const COLUMNS = [
        'categories' => ['id', 'name', 'sort_order'],
        'colors' => ['id', 'name', 'hex_code'],
        'products' => ['id', 'title', 'sku', 'currency', 'sale_price', 'stock_qty', 'description', 'is_active', 'category_id', 'color_id'],
        'product_variants' => ['id', 'product_id', 'size', 'sku', 'stock_qty', 'is_active'],
        'ai_photo_groups' => ['id', 'name', 'sort_order', 'ai_description'],
        'ai_photos' => ['id', 'ai_photo_group_id', 'path', 'sort_order', 'purpose'],
        'ai_photo_group_product' => ['id', 'ai_photo_group_id', 'product_id'],
        'ai_photo_product' => ['id', 'ai_photo_id', 'product_id'],
    ];

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) || DB::connection()->getDriverName() !== 'sqlite') {
            $this->error('Імпорт дозволено лише в локальну тестову SQLite.');

            return self::FAILURE;
        }
        $file = $this->argument('file');
        if (! is_file($file) || filesize($file) > 16 * 1024 * 1024) {
            $this->error('Файл не знайдений або перевищує 16 МБ.');

            return self::FAILURE;
        }
        try {
            $data = json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
            if (($data['version'] ?? null) !== 1 || ! is_string($data['exported_at'] ?? null)) {
                throw new \InvalidArgumentException;
            }
            $capturedAt = \Carbon\Carbon::parse($data['exported_at'])->toIso8601String();
            $purposes = $this->photoPurposes($data['tables']['ai_photos'] ?? []);
            if ($this->option('photo-purposes-only')) {
                $this->refreshPhotoPurposes($purposes, $capturedAt);
                $this->info('Призначення фото в GPT-копії оновлені. Каталог і переписки не змінені.');

                return self::SUCCESS;
            }
            foreach (self::COLUMNS as $table => $columns) {
                if (DB::table($table)->exists()) {
                    $this->error('Таблиця '.$table.' вже містить дані. Імпорт не перезаписує каталог.');

                    return self::FAILURE;
                }
                if (! isset($data['tables'][$table]) || ! is_array($data['tables'][$table])) {
                    throw new \InvalidArgumentException;
                }
                foreach ($data['tables'][$table] as $row) {
                    if (! is_array($row) || array_diff(array_keys($row), $columns)) {
                        throw new \InvalidArgumentException;
                    }
                }
            }
            // Застарілі зв’язки з уже видаленими фото не переносимо до локальної копії.
            $photoIds = array_column($data['tables']['ai_photos'], 'id');
            $productIds = array_column($data['tables']['products'], 'id');
            $data['tables']['ai_photo_product'] = array_values(array_filter($data['tables']['ai_photo_product'],
                fn ($row) => in_array($row['ai_photo_id'], $photoIds, true) && in_array($row['product_id'], $productIds, true)));
            // Не додаємо колонок до спільної галереї заради старої локальної схеми.
            if (! Schema::hasColumn('ai_photos', 'purpose')) {
                foreach ($data['tables']['ai_photos'] as &$photo) {
                    unset($photo['purpose']);
                }
                unset($photo);
            }
            DB::transaction(function () use ($data, $capturedAt, $purposes) {
                foreach (self::COLUMNS as $table => $columns) {
                    foreach (array_chunk($data['tables'][$table], 100) as $rows) {
                        DB::table($table)->insert($rows);
                    }
                }
                // Без позначки часу копія не повинна виглядати як поточний склад.
                $this->saveSource([
                    'database' => DB::connection()->getDatabaseName(), 'captured_at' => $capturedAt,
                    'photo_purposes' => $purposes, 'photo_purposes_captured_at' => $capturedAt,
                ]);
            });
        } catch (\Throwable $e) {
            $this->error('Не вдалося імпортувати копію каталогу. Перевірте структуру експорту. Тип: '.get_class($e));

            return self::FAILURE;
        }
        $this->info('Каталог імпортовано. GPT-чати, ключі та клієнтські переписки не змінені.');

        return self::SUCCESS;
    }

    private function photoPurposes(array $photos): array
    {
        $purposes = [];
        foreach ($photos as $photo) {
            if (! is_array($photo) || array_diff(array_keys($photo), self::COLUMNS['ai_photos'])) {
                throw new \InvalidArgumentException;
            }
            if (! isset($photo['purpose'])) {
                continue;
            }
            if (! is_int($photo['id'] ?? null) || $photo['id'] < 1 || ! is_string($photo['path'] ?? null)
                || ! in_array($photo['purpose'], ['product', 'size_guide'], true) || isset($purposes[$photo['id']])) {
                throw new \InvalidArgumentException;
            }
            $purposes[$photo['id']] = ['path' => $photo['path'], 'purpose' => $photo['purpose']];
        }

        return $purposes;
    }

    private function refreshPhotoPurposes(array $purposes, string $capturedAt): void
    {
        $source = Storage::disk('local')->exists('gpt-sandbox/catalog-source.json')
            ? json_decode(Storage::disk('local')->get('gpt-sandbox/catalog-source.json'), true) : null;
        if (! $purposes || ! is_array($source) || ($source['database'] ?? null) !== DB::connection()->getDatabaseName()) {
            throw new \InvalidArgumentException;
        }
        $paths = DB::table('ai_photos')->whereIn('id', array_keys($purposes))->pluck('path', 'id');
        foreach ($purposes as $id => $entry) {
            // ID недостатньо: фото могло бути замінене після створення копії.
            if ($paths->get($id) !== $entry['path']) {
                throw new \InvalidArgumentException;
            }
        }
        $source['photo_purposes'] = array_replace($source['photo_purposes'] ?? [], $purposes);
        $source['photo_purposes_captured_at'] = $capturedAt;
        $this->saveSource($source);
    }

    private function saveSource(array $source): void
    {
        if (! Storage::disk('local')->put('gpt-sandbox/catalog-source.json', json_encode($source, JSON_THROW_ON_ERROR))) {
            throw new \RuntimeException('Не вдалося зберегти метадані GPT-копії.');
        }
    }
}
