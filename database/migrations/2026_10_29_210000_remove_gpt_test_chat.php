<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\{Crypt, DB, File, Schema, Storage};

return new class extends Migration
{
    private const TABLES = ['gpt_sandbox_settings', 'gpt_sandbox_chats', 'gpt_sandbox_turns'];
    private const SCHEMA_MIGRATIONS = [
        '2026_10_28_100000_create_gpt_sandbox_tables.php',
        '2026_10_28_110000_add_connection_status_to_gpt_sandbox_settings.php',
        '2026_10_28_120000_add_gallery_photos_to_gpt_sandbox_turns.php',
        '2026_10_29_140000_add_test_checkout_to_gpt_sandbox.php',
        '2026_10_29_150000_add_store_to_gpt_sandbox_settings.php',
    ];

    public function up(): void
    {
        $disk = Storage::disk('local');
        $tables = [];
        foreach (self::TABLES as $table) {
            $tables[$table] = Schema::hasTable($table) ? DB::table($table)->get()->map(fn ($row) => (array) $row)->all() : [];
        }
        // Приватний зашифрований архів дозволяє відкотити видалення без втрати історії.
        $backup = Crypt::encryptString(json_encode($tables, JSON_THROW_ON_ERROR));
        if (! $disk->put($this->archive().'/tables.enc', $backup)) {
            throw new RuntimeException('Не вдалося зберегти архів тестового чату. Базу не змінено.');
        }
        if ($disk->directoryExists('gpt-sandbox')) {
            $target = $disk->path($this->archive().'/files');
            if (is_dir($target)) throw new RuntimeException('Архів файлів тестового чату вже існує. Потрібна перевірка перед повтором.');
            File::ensureDirectoryExists(dirname($target));
            if (! File::moveDirectory($disk->path('gpt-sandbox'), $target)) {
                throw new RuntimeException('Не вдалося перенести файли тестового чату до архіву. Базу не змінено.');
            }
        }
        foreach (array_reverse(self::TABLES) as $table) Schema::dropIfExists($table);
    }

    public function down(): void
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($this->archive().'/tables.enc')) throw new RuntimeException('Архів тестового чату недоступний для відкату.');
        // Повторний виклик після успішного відновлення не перезаписує дані.
        if (collect(self::TABLES)->contains(fn ($table) => Schema::hasTable($table))) return;
        $tables = json_decode(Crypt::decryptString($disk->get($this->archive().'/tables.enc')), true, 512, JSON_THROW_ON_ERROR);
        foreach (self::SCHEMA_MIGRATIONS as $file) (require database_path('migrations/'.$file))->up();
        DB::transaction(function () use ($tables) {
            foreach (self::TABLES as $table) {
                foreach (array_chunk($tables[$table], 100) as $rows) DB::table($table)->insert($rows);
            }
        });
        if ($disk->directoryExists($this->archive().'/files')) {
            if ($disk->directoryExists('gpt-sandbox') || ! File::moveDirectory($disk->path($this->archive().'/files'), $disk->path('gpt-sandbox'))) {
                throw new RuntimeException('Таблиці відновлено; файли залишились у приватному архіві.');
            }
        }
    }

    private function archive(): string
    {
        return 'retired/gpt-test-chat/'.hash('sha256', DB::connection()->getDatabaseName());
    }
};
