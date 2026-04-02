<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Оскільки на Mirohost Cron налаштовано на кожні 5 хвилин,
 * ми міняємо hourly() на everyFiveMinutes(), щоб система працювала синхронно.
 */

Schedule::command('delivery:sync-statuses')
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

Schedule::command('meta:sync-recent-conversations --platform=messenger --limit=25 --messages=20')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Fallback sync недавніх Messenger-діалогів з Meta, якщо webhook пропустив повідомлення');

Schedule::command('meta:sync-recent-conversations --platform=instagram --limit=25 --messages=20')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Fallback sync недавніх Instagram-діалогів з Meta, якщо webhook пропустив повідомлення');
