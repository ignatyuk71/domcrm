<?php

namespace App\Console\Commands;

use App\Models\InboxContact;
use App\Services\Meta\InboxMediaStore;
use Illuminate\Console\Command;

/**
 * Разовий backfill: перекачати аватарки контактів з CDN Meta до себе
 * (public/inbox-avatars), щоб браузер тягнув їх з нашого домену.
 * Ідемпотентна: локальні шляхи пропускає, мертві лінки лишає як є.
 */
class LocalizeInboxAvatars extends Command
{
    protected $signature = 'inbox:localize-avatars';

    protected $description = 'Перекачати аватарки контактів чату з CDN Meta на свій домен';

    public function handle(InboxMediaStore $store): int
    {
        $saved = 0;
        $failed = 0;

        $contacts = InboxContact::where('profile_pic', 'like', 'http%')->get();
        foreach ($contacts as $contact) {
            $local = $store->downloadAvatar($contact->profile_pic, $contact->id);
            if ($local) {
                $contact->update(['profile_pic' => $local]);
                $saved++;
            } else {
                $failed++; // протухлий лінк — оновиться тижневим рефетчем при новому повідомленні
            }
        }

        $this->info("Перекачано: {$saved}, не вдалося (протухлі лінки): {$failed}, всього з http: {$contacts->count()}.");

        return self::SUCCESS;
    }
}
