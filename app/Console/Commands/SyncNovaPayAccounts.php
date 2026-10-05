<?php

namespace App\Console\Commands;

use App\Models\NovaPayAccount;
use App\Services\NovaPay\SyncDispatcher;
use Illuminate\Console\Command;

class SyncNovaPayAccounts extends Command
{
    protected $signature = 'novapay:sync {--reconcile : Повторно перевірити останні дні}';

    protected $description = 'Поставити баланс і виписку NovaPay у фонову чергу';

    public function handle(SyncDispatcher $dispatcher): int
    {
        $count = 0;
        NovaPayAccount::query()->where('enabled', true)->with('connection')->each(function ($account) use ($dispatcher, &$count) {
            $count += $dispatcher->refresh($account, (bool) $this->option('reconcile'));
        });
        $this->info('Поставлено задач NovaPay: '.$count);

        return self::SUCCESS;
    }
}
