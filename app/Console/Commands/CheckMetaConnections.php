<?php

namespace App\Console\Commands;

use App\Models\MetaConnection;
use App\Services\Meta\MetaOAuthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Сторож токенів Meta: перевіряє кожне підключення живим запитом до Graph.
 * Мертвий токен → status=error + last_error (банер у чаті), знову живий →
 * повертаємо active. Мережеві збої/rate limit статус НЕ міняють, щоб банер
 * не блимав даремно.
 */
class CheckMetaConnections extends Command
{
    protected $signature = 'meta:check-connections';

    protected $description = 'Перевірка токенів підключень Meta (FB/IG) і позначка мертвих';

    public function handle(MetaOAuthService $oauth): int
    {
        foreach (MetaConnection::all() as $conn) {
            $check = $oauth->checkPageToken($conn->page_id, $conn->page_access_token);

            if ($check['ok']) {
                if ($conn->status !== 'active' || $conn->last_error) {
                    $conn->update(['status' => 'active', 'last_error' => null]);
                }
                $this->line("«{$conn->page_name}»: ok");
                continue;
            }

            if ($check['transient']) {
                $this->warn("«{$conn->page_name}»: тимчасовий збій, статус не міняю ({$check['error']})");
                continue;
            }

            $conn->update([
                'status' => 'error',
                'last_error' => 'Токен недійсний: ' . $check['error'] . '. Перепідключіть сторінку в Налаштування → Meta.',
            ]);
            Log::warning('Meta connection dead', ['page' => $conn->page_id, 'error' => $check['error']]);
            $this->error("«{$conn->page_name}»: ТОКЕН МЕРТВИЙ — {$check['error']}");
        }

        return self::SUCCESS;
    }
}