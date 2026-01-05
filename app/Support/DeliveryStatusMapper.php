<?php

namespace App\Support;

class DeliveryStatusMapper
{
    /**
     * Мапа статусів НП -> наші статуси з кольором та іконкою.
     * Коди взяті з довідника НП для TrackingDocument.
     */
    private const NP_STATUS_MAP = [
        '1' => ['code' => 'created', 'label' => 'Створена накладна', 'color' => '#4f46e5', 'icon' => 'bi-file-earmark'],
        '2' => ['code' => 'in_transit', 'label' => 'У дорозі', 'color' => '#2563eb', 'icon' => 'bi-truck'],
        '7' => ['code' => 'at_warehouse', 'label' => 'Прибув у відділення', 'color' => '#f59e0b', 'icon' => 'bi-building'],
        '8' => ['code' => 'delivered', 'label' => 'Доставлено', 'color' => '#16a34a', 'icon' => 'bi-check-circle'],
        '41' => ['code' => 'cod_on_way', 'label' => 'Доставлено, нак. платіж у дорозі', 'color' => '#f59e0b', 'icon' => 'bi-cash-coin'],
        '44' => ['code' => 'cod_received', 'label' => 'Доставлено, нак. платіж отримано', 'color' => '#16a34a', 'icon' => 'bi-cash-stack'],
        '9' => ['code' => 'returning', 'label' => 'Повертається', 'color' => '#f97316', 'icon' => 'bi-arrow-return-left'],
        '10' => ['code' => 'returned', 'label' => 'Повернено', 'color' => '#f59e0b', 'icon' => 'bi-check2-circle'],
        '11' => ['code' => 'utilization', 'label' => 'Утилізація', 'color' => '#fbbf24', 'icon' => 'bi-trash'],
        '102' => ['code' => 'error', 'label' => 'Помилка доставки', 'color' => '#ef4444', 'icon' => 'bi-x-octagon'],
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
}
