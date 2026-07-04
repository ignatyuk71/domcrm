<?php

namespace App\Jobs;

use App\Models\InboxMessage;
use App\Services\Meta\InboxMediaStore;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

/**
 * Докачати медіа вкладень повідомлення ПІСЛЯ відповіді вебхуку
 * (dispatchAfterResponse) — фейсбук одразу отримує 200, а завантаження
 * йде у фоні того ж процесу. Страховка від невдач — inbox:persist-media.
 */
class PersistInboxAttachments
{
    use Dispatchable;

    public function __construct(public int $messageId)
    {
    }

    public function handle(InboxMediaStore $store): void
    {
        $message = InboxMessage::find($this->messageId);
        if (!$message) {
            return;
        }

        try {
            $store->persistMessageAttachments($message);
        } catch (\Throwable $e) {
            Log::warning('Inbox media job failed', ['message' => $this->messageId, 'error' => $e->getMessage()]);
        }
    }
}