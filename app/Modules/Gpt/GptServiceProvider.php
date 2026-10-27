<?php

namespace App\Modules\Gpt;

use App\Modules\Gpt\Console\InspectCatalog;
use Illuminate\Support\ServiceProvider;

class GptServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
        if ($this->app->runningInConsole()) {
            $this->commands([InspectCatalog::class]);
        }
        // Модуль навмисно не реєструє слухачів Inbox, вебхуків чи відправників.
    }
}
