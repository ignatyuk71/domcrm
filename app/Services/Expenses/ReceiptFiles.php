<?php

namespace App\Services\Expenses;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class ReceiptFiles
{
    public static function deleteAfterCommit(array $paths): void
    {
        // Файли видаляємо тільки після commit: rollback не залишить запис без квитанції.
        DB::afterCommit(function () use ($paths) {
            foreach ($paths as $path) {
                try {
                    if (! Storage::disk('local')->delete($path)) {
                        Log::warning('Не вдалося видалити приватну квитанцію.', ['path' => $path]);
                    }
                } catch (\Throwable $error) {
                    report($error);
                }
            }
        });
    }
}
