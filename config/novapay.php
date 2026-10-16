<?php

return [
    'endpoint' => 'https://business.novapay.ua/Services/ClientAPIService.svc',
    'timeout' => 30,
    'timezone' => 'Europe/Kyiv',
    'initial_days' => (int) env('NOVAPAY_INITIAL_DAYS', 30),
    'reconcile_days' => (int) env('NOVAPAY_RECONCILE_DAYS', 7),
    'refresh_minutes' => (int) env('NOVAPAY_REFRESH_MINUTES', 5),
    // Увімкнути лише після звірки XML, ID, статусів і повноти виписки на реальному рахунку.
    'operations_verified' => (bool) env('NOVAPAY_OPERATIONS_VERIFIED', false),
    // Підтверджено на реальних платежах; невідомі статуси зупиняють імпорт періоду.
    'operation_statuses' => ['Conducted' => 'posted'],
];
