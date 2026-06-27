<?php

namespace App\Support;

class DeliveryStatusMapper
{
    // Офіційні коди НП
    public const NP_REGISTERED = 1;
    public const NP_DELETED = 2;
    public const NP_NOT_FOUND = 3;
    public const NP_IN_CITY = 4;
    public const NP_TO_CITY = 5;
    public const NP_IN_CITY_RECIPIENT = 6;
    public const NP_ARRIVED = 7;
    public const NP_ARRIVED_2 = 8;
    public const NP_RECEIVED = 9;
    public const NP_MONEY_RECEIVED = 10;
    public const NP_MONEY_RECEIVED_2 = 11;
    public const NP_ON_WAY_TO_RECIPIENT = 41;

    // Відмови
    public const NP_REFUSAL_RECIPIENT = 102;
    public const NP_REFUSAL_OTHER = 103;
    public const NP_REFUSAL_ADDRESS = 108;

    /**
     * Коди НП, які мапер СВІДОМО опрацьовує: або міняє статус замовлення,
     * або навмисно лишає без змін (як код 1 — ТТН створено).
     * Будь-який код ПОЗА цим списком вважаємо «невідомим» — його варто
     * залогувати й вирішити, куди мапити (напр. 105 «припинено зберігання»,
     * 106 «отримано+повернення», адресні коди). Це лише СИГНАЛ, не перехід.
     */
    public const HANDLED_CODES = [
        self::NP_REGISTERED,          // 1
        self::NP_DELETED,             // 2
        self::NP_NOT_FOUND,           // 3
        self::NP_IN_CITY,             // 4
        self::NP_TO_CITY,             // 5
        self::NP_IN_CITY_RECIPIENT,   // 6
        self::NP_ARRIVED,             // 7
        self::NP_ARRIVED_2,           // 8
        self::NP_RECEIVED,            // 9
        self::NP_MONEY_RECEIVED,      // 10
        self::NP_MONEY_RECEIVED_2,    // 11
        self::NP_ON_WAY_TO_RECIPIENT, // 41
        self::NP_REFUSAL_RECIPIENT,   // 102
        self::NP_REFUSAL_OTHER,       // 103
        self::NP_REFUSAL_ADDRESS,     // 108
    ];

    /** Чи цей код НП мапер свідомо опрацьовує (інакше — «невідомий», вартий уваги). */
    public static function isHandledCode(int $npCode): bool
    {
        return in_array($npCode, self::HANDLED_CODES, true);
    }

    /**
     * Мапа статусів НП -> наші статуси з кольором та іконкою.
     * Коди взяті з довідника НП для TrackingDocument.
     * Використовується для відображення лейблів в адмінці.
     */
    private const NP_STATUS_MAP = [
        '1' => ['code' => 'created', 'label' => 'Створена накладна', 'color' => '#37caec', 'icon' => 'bi-file-earmark'],
        '2' => ['code' => 'deleted', 'label' => 'Видалено', 'color' => '#8d9fa3', 'icon' => 'bi-trash'], // Виправлено лейбл
        
        // У дорозі
        '4' => ['code' => 'in_transit', 'label' => 'У місті відправника', 'color' => '#2563eb', 'icon' => 'bi-truck'],
        '5' => ['code' => 'in_transit', 'label' => 'Прямує до міста одержувача', 'color' => '#2563eb', 'icon' => 'bi-truck'],
        '6' => ['code' => 'in_transit', 'label' => 'У місті одержувача', 'color' => '#2563eb', 'icon' => 'bi-truck'],
        
        // Прибуло
        '7' => ['code' => 'at_warehouse', 'label' => 'Прибув у відділення', 'color' => '#f59e0b', 'icon' => 'bi-building'],
        '8' => ['code' => 'at_warehouse', 'label' => 'Прибув у відділення', 'color' => '#f59e0b', 'icon' => 'bi-building'], // Виправлено
        
        // Успішно отримано
        '9' => ['code' => 'received', 'label' => 'Отримано', 'color' => '#16a34a', 'icon' => 'bi-check-circle-fill'], // Виправлено
        '10' => ['code' => 'received_money', 'label' => 'Отримано (Гроші)', 'color' => '#16a34a', 'icon' => 'bi-cash-stack'], // Виправлено
        '11' => ['code' => 'received_money', 'label' => 'Отримано (Гроші)', 'color' => '#16a34a', 'icon' => 'bi-cash-stack'], // Виправлено

        // Післяплата
        '41' => ['code' => 'cod_on_way', 'label' => 'Отримано, гроші в дорозі', 'color' => '#16a34a', 'icon' => 'bi-cash-coin'],
        
        // Відмови / Проблеми
        '102' => ['code' => 'refusal', 'label' => 'Відмова одержувача', 'color' => '#ef4444', 'icon' => 'bi-x-circle'],
        '103' => ['code' => 'refusal', 'label' => 'Відмова (інше)', 'color' => '#ef4444', 'icon' => 'bi-x-circle'],
        '108' => ['code' => 'refusal', 'label' => 'Відмова (адреса)', 'color' => '#ef4444', 'icon' => 'bi-x-circle'],
    ];

    /**
     * Повертає нормалізований статус для НП-коду/рядка.
     *
     * @param array $npRow Відповідь НП по одній ТТН (row)
     * @return array{code:string,label:string,color:string,icon:string,description:?string,source_code:?string}
     */
    public static function map(array $npRow): array
    {
        $npCode = (string)($npRow['StatusCode'] ?? $npRow['Status'] ?? '');
        $sourceLabel = $npRow['Status'] ?? null;
        $description = $npRow['StatusDescription'] ?? $sourceLabel;

        $mapped = self::NP_STATUS_MAP[$npCode] ?? null;

        if ($mapped) {
            return [
                'code' => $mapped['code'],
                'label' => $mapped['label'],
                'color' => $mapped['color'],
                'icon' => $mapped['icon'],
                'description' => $description,
                'source_code' => $npCode ?: null,
            ];
        }

        return [
            'code' => 'unknown',
            'label' => $sourceLabel ?? 'Невідомий статус',
            'color' => '#6b7280',
            'icon' => 'bi-question-circle',
            'description' => $description,
            'source_code' => $npCode ?: null,
        ];
    }

    /**
     * Мапінг коду НП у CRM-статус (id).
     * Тут визначається, який ID статусу отримає замовлення в базі даних.
     */
    public static function getCrmStatusCode(int $npCode): ?string
    {
        // 1. Створено ТТН -> НЕ змінюємо CRM-статус.
        // Логіка пакування керується менеджером/пакувальником вручну.
        // Інакше cron може перезаписати "Упакування" -> "Запаковано" завчасно.
        if ($npCode === self::NP_REGISTERED) {
            return null;
        }

        // 2. У дорозі -> Відправлено (ID 5)
        if (in_array($npCode, [
            self::NP_IN_CITY,
            self::NP_TO_CITY,
            self::NP_IN_CITY_RECIPIENT,
            self::NP_ON_WAY_TO_RECIPIENT,
        ], true)) {
            return 'shipped';
        }

        // 3. Прибуло -> Прибуло у відділення (ID 6)
        if (in_array($npCode, [self::NP_ARRIVED, self::NP_ARRIVED_2], true)) {
            return 'delivered';
        }

        // 4. Отримано -> Успішно завершено (ID 11)
        if (in_array($npCode, [self::NP_RECEIVED, self::NP_MONEY_RECEIVED, self::NP_MONEY_RECEIVED_2], true)) {
            return 'delivered_paid';
        }

        // 5. Відмова -> Повернення (ID 8)
        if (in_array($npCode, [self::NP_REFUSAL_RECIPIENT, self::NP_REFUSAL_OTHER, self::NP_REFUSAL_ADDRESS], true)) {
            return 'returned';
        }

        // 6. Видалено/Не знайдено -> Скасовано (ID 7)
        if (in_array($npCode, [self::NP_DELETED, self::NP_NOT_FOUND], true)) {
            return 'cancelled';
        }

        return null;
    }
}
