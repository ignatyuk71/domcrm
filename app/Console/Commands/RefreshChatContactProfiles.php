<?php

namespace App\Console\Commands;

use App\Models\ChatContact;
use App\Services\ChatService;
use App\Services\MetaService;
use Illuminate\Console\Command;

class RefreshChatContactProfiles extends Command
{
    protected $signature = 'chat:refresh-contact-profiles
        {--platform= : Платформа контакту: messenger або instagram}
        {--limit=200 : Максимальна кількість контактів за запуск}
        {--hours=720 : Брати тільки контакти з активністю за останні N годин}
        {--missing-only : Оновлювати тільки контакти без нормального імені або аватара}';

    protected $description = 'Примусово оновлює профілі та аватари чат-контактів через Meta';

    public function handle(ChatService $chatService, MetaService $metaService): int
    {
        $platform = trim((string) $this->option('platform'));
        $limit = max(1, (int) $this->option('limit'));
        $hours = max(1, (int) $this->option('hours'));
        $missingOnly = (bool) $this->option('missing-only');

        if ($platform !== '' && !in_array($platform, ['messenger', 'instagram'], true)) {
            $this->error('Параметр --platform підтримує тільки messenger або instagram.');

            return self::INVALID;
        }

        $query = ChatContact::query()
            ->select('chat_contacts.*')
            ->leftJoin('chat_conversations', 'chat_conversations.contact_id', '=', 'chat_contacts.id')
            ->with(['customer', 'metaConnection', 'conversation'])
            ->whereHas('metaConnection', fn ($builder) => $builder->where('is_active', true))
            ->when($platform !== '', fn ($builder) => $builder->where('chat_contacts.platform', $platform))
            ->where(function ($builder) use ($hours) {
                $builder
                    ->whereNotNull('chat_conversations.last_message_at')
                    ->where('chat_conversations.last_message_at', '>=', now()->subHours($hours))
                    ->orWhere('chat_contacts.updated_at', '>=', now()->subHours($hours));
            })
            ->orderByRaw('COALESCE(chat_conversations.last_message_at, chat_contacts.updated_at) DESC')
            ->limit($limit);

        $contacts = $query->get();

        if ($contacts->isEmpty()) {
            $this->warn('Контакти для оновлення не знайдені.');

            return self::SUCCESS;
        }

        $processed = 0;
        $updated = 0;
        $restoredAvatars = 0;
        $skipped = 0;
        $failed = 0;

        $this->info("Знайдено {$contacts->count()} контактів для перевірки.");

        foreach ($contacts as $contact) {
            $customer = $contact->customer;

            if ($missingOnly && !$chatService->shouldRefreshContactProfile($contact, $customer)) {
                $skipped++;
                continue;
            }

            $beforeSignature = $this->profileSignature($contact);
            $hadAvatarBefore = $this->hasStoredAvatar($contact);

            try {
                $refreshed = $chatService->syncContactProfile($contact, $metaService, $customer);
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("Помилка контакту #{$contact->id}: {$e->getMessage()}");
                continue;
            }

            $processed++;

            if ($this->profileSignature($refreshed) !== $beforeSignature) {
                $updated++;
            }

            if (!$hadAvatarBefore && $this->hasStoredAvatar($refreshed)) {
                $restoredAvatars++;
            }
        }

        $this->info("Оброблено: {$processed}");
        $this->info("Оновлено: {$updated}");
        $this->info("Відновлено аватарів: {$restoredAvatars}");
        $this->info("Пропущено: {$skipped}");
        $this->info("Помилок: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function hasStoredAvatar(ChatContact $contact): bool
    {
        return trim((string) $contact->avatar_path) !== ''
            || trim((string) $contact->avatar_original_url) !== ''
            || trim((string) ($contact->customer?->fb_profile_pic ?? '')) !== '';
    }

    private function profileSignature(ChatContact $contact): string
    {
        return implode('|', [
            trim((string) $contact->display_name),
            trim((string) $contact->first_name),
            trim((string) $contact->last_name),
            trim((string) $contact->avatar_path),
            trim((string) $contact->avatar_original_url),
            trim((string) ($contact->customer?->fb_profile_pic ?? '')),
        ]);
    }
}
