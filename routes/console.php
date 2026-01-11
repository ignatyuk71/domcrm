<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('delivery:sync-statuses')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Автооновлення статусів доставки (НП) кожну годину');

Schedule::command('fiscal:delivered')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Автофіскалізація доставлених замовлень та переведення у статус оплачених');
