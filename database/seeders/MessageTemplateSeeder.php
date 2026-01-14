<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MessageTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = Carbon::now();

        $templates = [
            [
                'title' => '👋 Вітання (Перший контакт)',
                'content' => "Доброго дня, {{name}}! Дякуємо за звернення. Підкажіть, вас цікавить наявність чи потрібна консультація по розмірах?",
                'sort_order' => 10,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => '🚚 Відправка ТТН',
                'content' => "Ваше замовлення відправлено!\nТТН: {{ttn}}\nВідстежити можна тут: https://novaposhta.ua/",
                'sort_order' => 20,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => '💳 Реквізити (ФОП)',
                'content' => "Оплата на рахунок IBAN: UA00000000000000000000\nСума: {{price}} грн.\nПризначення платежу: Замовлення №{{order_id}}",
                'sort_order' => 30,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => '🕑 Ми не працюємо (Ніч)',
                'content' => "На жаль, зараз неробочий час. Ми зв'яжемось з вами завтра після 10:00. Дякуємо за розуміння!",
                'sort_order' => 0,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => '⛔️ Архівний шаблон (Стара акція)',
                'content' => "Знижка -20% тільки сьогодні!",
                'sort_order' => 5,
                'is_active' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('message_templates')->insert($templates);
    }
}
