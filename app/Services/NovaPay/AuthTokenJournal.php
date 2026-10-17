<?php

namespace App\Services\NovaPay;

use App\Models\NovaPayConnection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class AuthTokenJournal
{
    public function fingerprint(NovaPayConnection $connection): string
    {
        return $this->credentialsFingerprint($connection->login, $connection->refresh_token, $connection->public_certificate);
    }

    public function credentialsFingerprint(string $login, string $refresh, string $certificate): string
    {
        return hash_hmac('sha256', json_encode([$login, $refresh, $certificate], JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function read(int $connectionId): ?array
    {
        $path = $this->path($connectionId);
        if (! file_exists($path)) return null;
        try {
            $contents = @file_get_contents($path);
            if ($contents === false) throw new \RuntimeException;
            $record = json_decode(Crypt::decryptString($contents), true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($record) || ! preg_match('/^[a-f0-9]{64}$/D', $record['base'] ?? '')
                || ! in_array($record['phase'] ?? '', ['pending', 'received'], true)) throw new \RuntimeException;
            if ($record['phase'] === 'received') {
                if (! is_int($record['received_at'] ?? null) || $record['received_at'] < 1) throw new \RuntimeException;
                foreach (['refresh_token', 'public_certificate', 'access_token'] as $key) {
                    if (! is_string($record['credentials'][$key] ?? null) || $record['credentials'][$key] === '') throw new \RuntimeException;
                }
            }

            return $record;
        } catch (\Throwable) {
            throw $this->storageFailure();
        }
    }

    public function write(int $connectionId, array $record): void
    {
        $path = $this->path($connectionId);
        $temporary = $path.'.'.Str::uuid().'.tmp';
        try {
            $directory = dirname($path);
            if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) throw new \RuntimeException;
            if (! @chmod($directory, 0700)) throw new \RuntimeException;
            $encrypted = Crypt::encryptString(json_encode($record, JSON_THROW_ON_ERROR));
            // Атомарний зашифрований журнал переживає збій БД та перезапуск worker.
            if (@file_put_contents($temporary, $encrypted, LOCK_EX) !== strlen($encrypted)
                || ! @chmod($temporary, 0600) || ! @rename($temporary, $path)) throw new \RuntimeException;
        } catch (\Throwable) {
            @unlink($temporary);
            throw $this->storageFailure();
        }
    }

    public function forget(int $connectionId): void
    {
        $path = $this->path($connectionId);
        if (file_exists($path) && ! @unlink($path)) throw $this->storageFailure();
    }

    private function path(int $connectionId): string
    {
        return rtrim(config('novapay.auth_journal_directory', storage_path('app/private/novapay/auth')), '/').'/'.$connectionId.'.enc';
    }

    private function storageFailure(): NovaPayException
    {
        return new NovaPayException('auth_storage', 'Не вдалося прочитати або зберегти захищений стан авторизації NovaPay. Повторну ротацію заблоковано до відновлення сховища.', true);
    }
}
