<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Крон на проді (Hetzner): /etc/cron.d/domcrm запускає schedule:run щохвилини,
 * тож інтервали нижче спрацьовують точно за розкладом.
 */

Schedule::command('delivery:sync-statuses --chunk=100')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Автооновлення статусів доставки (НП) кожні 5 хвилин');

Schedule::command('fiscal:delivered')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Автофіскалізація доставлених замовлень кожні 5 хвилин');

Schedule::command('fiscal:shift-manager')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Автоуправління зміною Checkbox та запуск черги фіскалізації');

Schedule::command('packing:auto-release')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Автоматичне розблокування завислих пакувань');

Schedule::command('ai:sweep')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Страховка ШІ: добрати вхідні без реакції (фоновий процес вбито хостингом)');

Schedule::command('inbox:persist-media --days=7 --limit=300')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Докачування медіа переписок до себе (посилання Meta тимчасові)');

Schedule::command('meta:check-connections')
    ->everySixHours()
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Сторож токенів Meta: мертве підключення → банер у чаті');

Schedule::command('inbox:prune-media')
    ->dailyAt('04:20')
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Чистка медіа переписок, старших за 90 днів (теки не ростуть вічно)');
