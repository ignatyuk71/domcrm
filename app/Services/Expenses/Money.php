<?php

namespace App\Services\Expenses;

final class Money
{
    public static function minor(string|int|float $value, int $scale = 2): int
    {
        [$whole, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');

        return ((int) $whole * (10 ** $scale)) + (int) str_pad($fraction, $scale, '0');
    }

    public static function display(int|string|null $minor): string
    {
        $minor = (int) $minor;

        return ($minor < 0 ? '-' : '').intdiv(abs($minor), 100).'.'.str_pad((string) (abs($minor) % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function convert(int $minor, string $rate): int
    {
        // Округлення до копійок без двійкових похибок float.
        return intdiv($minor * self::minor($rate, 6) + 500000, 1000000);
    }
}
