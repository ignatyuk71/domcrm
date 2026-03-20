<?php

namespace App\Jobs;

use App\Models\ChatContact;
use App\Services\ChatService;
use App\Services\MetaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncMetaContactProfileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Кількість спроб виконання завдання (на випадок збоїв API Meta).
     */
    public int $tries = 3;

    /**
     * Час очікування перед повторною спробою (в секундах).
     */
    public int $backoff = 60;

    public function __construct(
        private readonly int $contactId
    ) {
    }

    public function handle(ChatService $chatService, MetaService $metaService): void
    {
        $contact = ChatContact::find($this->contactId);

        if (!$contact) {
            return;
        }

        // Викликаємо логіку синхронізації, яка завантажить аватарку локально
        $chatService->syncContactProfile($contact, $metaService);
    }
}