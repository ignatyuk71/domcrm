<?php
// Підключення до БД напряму з Laravel .env
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    // Збільшуємо ліміт для аватарки клієнта
    DB::statement("ALTER TABLE customers MODIFY fb_profile_pic TEXT NULL");
    // Збільшуємо ліміт для вкладень повідомлень
    DB::statement("ALTER TABLE facebook_messages MODIFY attachments TEXT NULL");
    
    echo "<h1>✅ Успіх! Базу виправлено.</h1>";
    echo "<p>Тепер спробуйте відкрити CRM або phpMyAdmin.</p>";
} catch (\Exception $e) {
    echo "<h1>❌ Помилка:</h1><pre>" . $e->getMessage() . "</pre>";
}