<?php

namespace App\Console\Commands;

use App\Models\InboxMessage;
use App\Services\Meta\InboxMediaStore;
use Illuminate\Console\Command;

/**
 * Backfill/страховка: докачати медіа вкладень, які ще без локальної копії.
 * Щогодини добирає свіже (де вебхучне завантаження не вдалося),
 * а з --days=0 — разовий прохід по всій історії.
 */
class PersistInboxMedia extends Command
{
    protected $signature = 'inbox:persist-media
        {--days=7 : Вікно за датою повідомлення (0 = без обмеження)}
        {--limit=300 : Максимум повідомлень за прохід}';

    protected $description = 'Докачати медіа вкладень переписок до себе (посилання Meta тимчасові)';

    public function handle(InboxMediaStore $store): int
    {
        $days = (int) $this->option('days');
        $limit = (int) $this->option('limit');

        $query = InboxMessage::query()
            ->whereNotNull('attachments')
            ->orderByDesc('id');

        if ($days > 0) {
            $query->where('created_at', '>=', now()->subDays($days));
        }

        $processed = 0;
        $saved = 0;

        foreach ($query->lazy(100) as $message) {
            if ($processed >= $limit) {
                break;
            }
            $needsWork = collect($message->attachments ?? [])->contains(
                fn ($a) => empty($a['local']) && empty($a['dead']) && !empty($a['url'])
            );
            if (!$needsWork) {
                continue;
            }

            $processed++;
            $before = collect($message->attachments)->whereNotNull('local')->count();
            $store->persistMessageAttachments($message);
            $saved += collect($message->attachments)->whereNotNull('local')->count() - $before;
        }

        $this->info("Оброблено повідомлень: {$processed}, докачано файлів: {$saved}.");

        return self::SUCCESS;
    }
}