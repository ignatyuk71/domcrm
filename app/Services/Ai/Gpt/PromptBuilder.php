<?php

namespace App\Services\Ai\Gpt;

use App\Models\AiSetting;
use App\Models\MetaConnection;

/**
 * Незалежний системний промпт GPT (правила + каталог) і описи інструментів.
 * Незалежна реалізація робочого каналу GPT. Каталог тягне CatalogBuilder, тексти — OrderTexts.
 */
class PromptBuilder
{
    public function __construct(private CatalogBuilder $catalog)
    {
    }

    /**
     * Узгоджені інструкції GPT та повний актуальний каталог.
     * Винесено окремо, щоб тести й дрон-прогони били 1:1 у бойовий промпт.
     */
    public function buildSystemPrompt(?int $connectionId = null): array
    {
        $connectionId ??= $this->defaultConnectionId();
        $store = $connectionId ? AiSetting::query()->where('meta_connection_id', $connectionId)->first() : null;
        $storePrompt = $store?->system_prompt;
        $pageName = ($connectionId ? MetaConnection::query()->whereKey($connectionId)->value('page_name') : null) ?? 'магазин';
        $texts = OrderTexts::all();

        $rules = ConsultantPrompt::instructions($pageName, $storePrompt, $texts);

        return [
            // Правила та живий каталог залишаються окремими блоками GPT.
            ['type' => 'text', 'text' => $rules],
            [
                'type' => 'text',
                'text' => "КАТАЛОГ МАГАЗИНУ (повний, актуальний на зараз):\n" . $this->catalog->buildCatalog(),
            ],
        ];
    }

    /** За кількох магазинів джерело інструкції обирає власник. */
    private function defaultConnectionId(): ?int
    {
        $ids = AiSetting::query()->whereNotNull('meta_connection_id')->pluck('meta_connection_id');
        if ($ids->count() > 1) {
            throw new GptFailure('Оберіть магазин у налаштуваннях GPT, щоб використати його чинну інструкцію.');
        }

        return $ids->first();
    }

    /** Описи інструментів магазину; формат Responses API адаптує CatalogProtocol. */
    public function toolDefinitions(): array
    {
        return [
            ...CheckoutProtocol::tools(),
            [
                'name' => 'get_product',
                'description' => 'Повна картка товару за його id: усі розміри з реальними залишками, ціна та ОПИС з деталями (матеріал, хутро, підошва, стельки в см, догляд/прання, маломірність). Викликай для питань про характеристики конкретної моделі.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => ['type' => 'integer', 'description' => 'id товару з каталогу (число після #)'],
                    ],
                    'required' => ['product_id'],
                ],
            ],
            [
                'name' => 'ask_delivery_details',
                'description' => 'Надіслати клієнту готову форму-шаблон для збору даних доставки (ПІБ, номер телефону, адреса/відділення Нової Пошти, спосіб оплати). Виклич ОДИН раз, коли клієнт підтвердив, що хоче замовити, і ти вже стисло підсумував позиції (модель/колір/розмір/кількість). Сам форму не друкуй — інструмент надішле її дослівно.',
                'input_schema' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'escalate_to_manager',
                'description' => 'Передає розмову людині та ставить чат на паузу, коли покупець прямо просить людину, має скаргу/повернення/обмін/проблему із замовленням або для змістовного питання бракує перевірених даних після перевірки доступних джерел. Звичайні ціна, розмір, колір, фото й підбір не потребують передачі.'.ConversationOpening::TOOL_RULE,
                'input_schema' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'send_photos',
                'description' => 'Надіслати клієнту зображення за номерами з полів «фото», «колаж» і «пояснення_розміру» каталогу та get_product. До 3 за виклик. Колаж показує групу, фото — конкретний колір. Пояснення розміру надсилай разом із відповіддю про заміри, посадку, маломірність або бортик прикріпленої моделі.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'photo_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'Номери фото, напр. [11] або [13, 14]',
                        ],
                    ],
                    'required' => ['photo_ids'],
                ],
            ],
        ];
    }
}
