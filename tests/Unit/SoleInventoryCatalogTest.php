<?php

namespace Tests\Unit;

use App\Services\Inventory\SoleInventoryCatalog;
use PHPUnit\Framework\TestCase;

class SoleInventoryCatalogTest extends TestCase
{
    public function test_normalizes_size_labels_but_never_guesses_typographical_errors(): void
    {
        $catalog = new SoleInventoryCatalog;
        $category = (object) ['name' => 'Капці для вулиці (хутряні)'];
        foreach (['36/37', '36/37-24см', '36-37-24 см', '36/37р - 24-24,5см', ' 36 / 37 — 24.5 см'] as $value) {
            $this->assertSame('36/37', $catalog->normalizeSize($value, $category));
        }
        foreach (['42/23', '36/370', '24 см', 'розмір 36/37', null, ''] as $value) {
            $this->assertNull($catalog->normalizeSize($value, $category));
        }
        $this->assertSame('42/43', $catalog->normalizeSize('42-43-27см', $category));
        $this->assertNull($catalog->normalizeSize('42/43', (object) ['name' => 'Домашні капці Halluci (хутряні)']));
    }
}
