<?php

namespace Tests\Unit;

use App\Support\DeliveryStatusMapper;
use PHPUnit\Framework\TestCase;

/**
 * Захищає мапінг кодів НП → статус CRM (грошовий ланцюг: код 9/10/11 веде до
 * фіскалізації, відмови — у повернення). І фіксує контракт «опрацьовані коди».
 */
class DeliveryStatusMapperTest extends TestCase
{
    public function test_delivery_palette_preserves_status_identity_and_source_details(): void
    {
        $cases = [
            [1, 'created', 'Створена накладна', '#78716c', 'bi-file-earmark'],
            [2, 'deleted', 'Видалено', '#1f2937', 'bi-trash'],
            [3, 'unknown', 'Номер не знайдено', '#f59e0b', 'bi-question-circle'],
            [4, 'in_transit', 'У місті відправника', '#0ea5e9', 'bi-truck'],
            [5, 'in_transit', 'Прямує до міста одержувача', '#0ea5e9', 'bi-truck'],
            [6, 'in_transit', 'У місті одержувача', '#0ea5e9', 'bi-truck'],
            [7, 'at_warehouse', 'Прибув у відділення', '#f59e0b', 'bi-building'],
            [8, 'at_warehouse', 'Прибув у відділення', '#f59e0b', 'bi-building'],
            [9, 'received', 'Отримано', '#16a34a', 'bi-check-circle-fill'],
            [10, 'received_money', 'Отримано (Гроші)', '#16a34a', 'bi-cash-stack'],
            [11, 'received_money', 'Отримано (Гроші)', '#16a34a', 'bi-cash-stack'],
            [41, 'cod_on_way', 'Отримано, гроші в дорозі', '#16a34a', 'bi-cash-coin'],
            [102, 'refusal', 'Відмова одержувача', '#ef4444', 'bi-x-circle'],
            [103, 'refusal', 'Відмова (інше)', '#ef4444', 'bi-x-circle'],
            [108, 'refusal', 'Відмова (адреса)', '#ef4444', 'bi-x-circle'],
            [104, 'in_transit', 'Змінено адресу доставки', '#f59e0b', 'bi-geo-alt'],
        ];

        foreach ($cases as [$sourceCode, $code, $label, $color, $icon]) {
            $this->assertSame([
                'code' => $code,
                'label' => $label,
                'color' => $color,
                'icon' => $icon,
                'description' => 'Опис перевізника',
                'source_code' => (string) $sourceCode,
            ], DeliveryStatusMapper::map([
                'StatusCode' => (string) $sourceCode,
                'Status' => $label,
                'StatusDescription' => 'Опис перевізника',
            ]), "Код НП {$sourceCode}");
        }

        // Непізнаний код не успадковує попереджувальний колір помилки пошуку ТТН.
        $unknown = DeliveryStatusMapper::map(['StatusCode' => '105', 'Status' => 'Інший стан']);
        $this->assertSame('unknown', $unknown['code']);
        $this->assertSame('#6b7280', $unknown['color']);
        $this->assertSame('Інший стан', $unknown['label']);
    }

    public function test_received_codes_map_to_delivered_paid(): void
    {
        // 9/10/11 = отримано → delivered_paid (тригер фіскалізації). Не зламати!
        $this->assertSame('delivered_paid', DeliveryStatusMapper::getCrmStatusCode(9));
        $this->assertSame('delivered_paid', DeliveryStatusMapper::getCrmStatusCode(10));
        $this->assertSame('delivered_paid', DeliveryStatusMapper::getCrmStatusCode(11));
    }

    public function test_transit_arrival_refusal_codes(): void
    {
        $this->assertSame('shipped', DeliveryStatusMapper::getCrmStatusCode(5));
        $this->assertSame('shipped', DeliveryStatusMapper::getCrmStatusCode(41));
        $this->assertSame('delivered', DeliveryStatusMapper::getCrmStatusCode(7));
        $this->assertSame('delivered', DeliveryStatusMapper::getCrmStatusCode(8));
        $this->assertSame('returned', DeliveryStatusMapper::getCrmStatusCode(102));
    }

    public function test_unavailable_waybill_does_not_cancel_order(): void
    {
        // Видалена або ще недоступна в трекінгу ТТН не скасовує продаж.
        foreach ([DeliveryStatusMapper::NP_DELETED, DeliveryStatusMapper::NP_NOT_FOUND] as $code) {
            $this->assertNull(DeliveryStatusMapper::getCrmStatusCode($code));
            $this->assertTrue(DeliveryStatusMapper::isHandledCode($code));
        }
    }

    public function test_registered_code_does_not_change_status(): void
    {
        // Код 1 (ТТН створено) — навмисно не міняємо статус, але він «опрацьований».
        $this->assertNull(DeliveryStatusMapper::getCrmStatusCode(1));
        $this->assertTrue(DeliveryStatusMapper::isHandledCode(1));
    }

    public function test_address_changed_is_handled_without_status_change(): void
    {
        // 104 (змінено адресу) — опрацьований, але статус НЕ рухає: код може
        // прийти після «прибуло», і авто-перехід відкотив би замовлення назад.
        $this->assertNull(DeliveryStatusMapper::getCrmStatusCode(104));
        $this->assertTrue(DeliveryStatusMapper::isHandledCode(104));
        $this->assertSame('in_transit', DeliveryStatusMapper::map(['StatusCode' => '104'])['code']);
    }

    public function test_unknown_codes_are_flagged_as_unhandled(): void
    {
        // 105 (припинено зберігання), 106 (отримано+повернення)
        // — поки не змаплені: статус не міняється і код вважається «невідомим».
        foreach ([105, 106] as $code) {
            $this->assertNull(DeliveryStatusMapper::getCrmStatusCode($code), "code {$code} не має міняти статус");
            $this->assertFalse(DeliveryStatusMapper::isHandledCode($code), "code {$code} має бути невідомим");
        }
    }

    public function test_all_handled_codes_either_map_or_are_intentional(): void
    {
        // Контракт: кожен «опрацьований» код або дає статус, або це навмисний null
        // (ТТН створено/видалено/не знайдено або змінено адресу).
        $intentionalNull = [
            DeliveryStatusMapper::NP_REGISTERED,
            DeliveryStatusMapper::NP_DELETED,
            DeliveryStatusMapper::NP_NOT_FOUND,
            DeliveryStatusMapper::NP_ADDRESS_CHANGED,
        ];
        foreach (DeliveryStatusMapper::HANDLED_CODES as $code) {
            $mapped = DeliveryStatusMapper::getCrmStatusCode($code);
            if (in_array($code, $intentionalNull, true)) {
                $this->assertNull($mapped);
            } else {
                $this->assertNotNull($mapped, "опрацьований код {$code} має давати статус");
            }
        }
    }
}
