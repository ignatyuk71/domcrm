<?php

namespace App\Providers;

use App\Models\ChatMessage;
use App\Observers\ChatMessageObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ChatMessage::observe(ChatMessageObserver::class);
    }
}
