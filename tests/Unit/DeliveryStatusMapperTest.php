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
        $this->assertSame('cancelled', DeliveryStatusMapper::getCrmStatusCode(2));
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
        // (код 1 — ТТН створено, код 104 — змінено адресу).
        $intentionalNull = [DeliveryStatusMapper::NP_REGISTERED, DeliveryStatusMapper::NP_ADDRESS_CHANGED];
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
