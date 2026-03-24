<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ChatAiBootstrapSeeder extends Seeder
{
    public function run(): void
    {
        if (
            !Schema::hasTable('chat_ai_agents')
            || !Schema::hasTable('chat_ai_prompt_versions')
        ) {
            $this->command?->warn('AI-таблиці ще не створені. Спочатку виконайте міграції.');

            return;
        }

        $now = now();
        $agentCode = 'sales_assistant_v1';

        DB::table('chat_ai_agents')->updateOrInsert(
            ['code' => $agentCode],
            [
                'name' => 'Sales Assistant v1',
                'is_active' => true,
                'provider' => 'openai',
                'model' => (string) config('services.openai.model', 'gpt-4.1-mini'),
                'temperature' => 0.30,
                'max_output_tokens' => 300,
                'config_json' => json_encode([
                    'reply_style' => 'short_consultative',
                    'single_question_per_turn' => true,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        $agentId = DB::table('chat_ai_agents')
            ->where('code', $agentCode)
            ->value('id');

        if (!$agentId) {
            $this->command?->error('Не вдалося отримати agent_id для seed.');

            return;
        }

        $prompts = [
            'interest' => [
                'version' => 1,
                'system_prompt' => <<<PROMPT
Ти консультант e-commerce магазину.
Працюй на етапі зацікавлення клієнта.
Твоя задача: коротко відповісти по суті (ціна, наявність, фото, кольори, розмірна сітка) і поставити лише 1 логічне наступне питання.
Не тисни на клієнта.
PROMPT,
                'policy_json' => [
                    'forbidden_actions' => [
                        'ask_delivery_data',
                        'ask_payment_method',
                    ],
                    'max_new_questions' => 1,
                ],
            ],
            'selection' => [
                'version' => 1,
                'system_prompt' => <<<PROMPT
Ти консультант e-commerce магазину.
Працюй на етапі підбору товару.
Уточнюй тільки дані, без яких неможливо запропонувати релевантний варіант: розмір, колір, модель.
Став 1 питання за раз.
PROMPT,
                'policy_json' => [
                    'forbidden_actions' => [
                        'ask_delivery_data',
                    ],
                    'required_slots_before_checkout' => [
                        'selected_product',
                        'selected_size',
                        'selected_variant',
                        'purchase_intent',
                    ],
                ],
            ],
            'checkout_ready' => [
                'version' => 1,
                'system_prompt' => <<<PROMPT
Клієнт готовий оформляти замовлення.
Попроси дані коротко і структуровано: ім'я, телефон, місто, відділення/поштомат.
Не питай зайвого, якщо дані вже є в діалозі.
PROMPT,
                'policy_json' => [
                    'max_new_questions' => 1,
                    'collect_fields' => [
                        'name',
                        'phone',
                        'city',
                        'warehouse',
                    ],
                ],
            ],
            'checkout' => [
                'version' => 1,
                'system_prompt' => <<<PROMPT
Заверши оформлення.
Перевір повноту даних, підтверди прийняття замовлення і коротко опиши наступний крок.
PROMPT,
                'policy_json' => [
                    'confirm_order_when_all_fields_collected' => true,
                    'avoid_redundant_questions' => true,
                ],
            ],
        ];

        foreach ($prompts as $stage => $payload) {
            DB::table('chat_ai_prompt_versions')->updateOrInsert(
                [
                    'agent_id' => $agentId,
                    'stage' => $stage,
                    'version' => (int) $payload['version'],
                ],
                [
                    'system_prompt' => $payload['system_prompt'],
                    'policy_json' => json_encode($payload['policy_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'is_current' => true,
                    'created_by' => null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            $currentId = DB::table('chat_ai_prompt_versions')
                ->where('agent_id', $agentId)
                ->where('stage', $stage)
                ->where('version', (int) $payload['version'])
                ->value('id');

            if ($currentId) {
                DB::table('chat_ai_prompt_versions')
                    ->where('agent_id', $agentId)
                    ->where('stage', $stage)
                    ->where('id', '!=', $currentId)
                    ->update([
                        'is_current' => false,
                        'updated_at' => $now,
                    ]);
            }
        }

        $this->command?->info('AI bootstrap seed виконано: агент і промпти створені/оновлені.');
    }
}

