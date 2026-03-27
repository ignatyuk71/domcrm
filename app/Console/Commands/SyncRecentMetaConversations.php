<?php

namespace App\Console\Commands;

use App\Services\MetaService;
use Illuminate\Console\Command;

class SyncRecentMetaConversations extends Command
{
    protected $signature = 'meta:sync-recent-conversations
        {--platform=messenger : Платформа для fallback sync}
        {--limit=25 : Максимальна кількість недавніх діалогів}
        {--messages=20 : Максимальна кількість повідомлень на один діалог}';

    protected $description = 'Підтягує недавні діалоги з Meta як fallback, якщо webhook пропустив вхідні повідомлення';

    public function handle(MetaService $metaService): int
    {
        $platform = trim((string) $this->option('platform'));
        $limit = max(1, (int) $this->option('limit'));
        $messagesLimit = max(1, (int) $this->option('messages'));

        if (!in_array($platform, ['messenger', 'instagram'], true)) {
            $this->error('Параметр --platform підтримує тільки messenger або instagram.');

            return self::INVALID;
        }

        $added = $metaService->syncRecentConversations($platform, $limit, $messagesLimit);

        $this->info("Fallback sync завершено. Додано повідомлень: {$added}");

        return self::SUCCESS;
    }
}
