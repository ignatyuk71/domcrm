<?php

namespace App\Console\Commands;

use App\Models\TelegramSetting;
use App\Services\TelegramBotService;
use App\Services\TelegramWarehouseDigest;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendTelegramWarehouseDigest extends Command
{
    protected $signature = 'telegram:warehouse-digest {--dry-run : Порахувати повідомлення без надсилання}';
    protected $description = 'Щоденний список посилок на 5–7-й день у відділенні';

    public function handle(TelegramWarehouseDigest $digest, TelegramBotService $bot): int
    {
        $settings = TelegramSetting::current();
        if (!$settings->allows('warehouse_reminder')) {
            $this->info('Нагадування вимкнені.');
            return self::SUCCESS;
        }
        $now = CarbonImmutable::now('Europe/Kyiv');
        $messages = $digest->messages($now);
        if ($this->option('dry-run')) {
            $this->info('Частин повідомлення: '.count($messages));
            return self::SUCCESS;
        }
        // Фіксуємо спробу ДО HTTP: після таймауту доставка могла відбутися.
        // Автоматичний повтор за цей день заборонено, навіть після часткової помилки.
        $claimed = DB::table('telegram_digest_runs')->insertOrIgnore([
            'digest_date' => $now->toDateString(), 'status' => 'sending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if (!$claimed) {
            $this->info('Сьогоднішній запуск уже зафіксовано.');
            return self::SUCCESS;
        }
        $run = DB::table('telegram_digest_runs')->where('digest_date', $now->toDateString());
        try {
            foreach ($messages as $message) {
                $bot->sendWarehouseDigest(TelegramSetting::current(), $message);
                (clone $run)->increment('sent_parts');
            }
            $run->update(['status' => $messages ? 'sent' : 'empty', 'updated_at' => now()]);
        } catch (Throwable) {
            $run->update(['status' => 'failed', 'updated_at' => now()]);
            Log::error('Telegram: щоденний список не завершено; автоматичний повтор заблоковано.', ['date' => $now->toDateString()]);
            $this->error('Не вдалося завершити надсилання. Перевірте групу та журнал запусків.');
            return self::FAILURE;
        }
        $this->info('Щоденну перевірку завершено.');
        return self::SUCCESS;
    }
}
