<?php

namespace App\Modules\GptSandbox;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class GptSandboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Знімок дозволений лише локальному стенду; робочі чати завжди читають CRM.
        $this->app->scoped(Infrastructure\CatalogReader::class, fn () => new Infrastructure\CatalogReader(
            app()->environment(['local', 'testing']) && request()->is('settings/gpt-sandbox/*')
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
        $this->loadViewsFrom(__DIR__.'/Views', 'gpt-sandbox');
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
        if ($this->app->runningInConsole()) {
            $this->commands([\App\Modules\GptSandbox\Console\ImportCatalogSnapshot::class]);
        }

        RateLimiter::for('gpt-sandbox', fn (Request $request) => Limit::perMinute(30)->by((string) ($request->user()?->id ?? $request->ip()))
        );
    }
}
