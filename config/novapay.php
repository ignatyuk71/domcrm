<?php

return [
    'endpoint' => 'https://business.novapay.ua/Services/ClientAPIService.svc',
    'timeout' => 30,
    'timezone' => 'Europe/Kyiv',
    'initial_days' => (int) env('NOVAPAY_INITIAL_DAYS', 30),
    'reconcile_days' => (int) env('NOVAPAY_RECONCILE_DAYS', 7),
    'refresh_times' => ['14:30', '20:15', '23:45'],
    'refresh_grace_minutes' => 10,
    'auth_journal_directory' => storage_path('app/private/novapay/auth'),
    // Увімкнути лише після звірки XML, ID, статусів і повноти виписки на реальному рахунку.
    'operations_verified' => (bool) env('NOVAPAY_OPERATIONS_VERIFIED', false),
    // Лише перевірені зіставлення; решта зберігається як unknown без підтвердження сум.
    'operation_statuses' => ['Conducted' => 'posted'],
    'payouts_verified' => (bool) env('NOVAPAY_PAYOUTS_VERIFIED', false),
    'register_download_host' => 'storage.googleapis.com',
    'register_download_prefix' => '/prod-novaclients3-cod-statements/',
];
