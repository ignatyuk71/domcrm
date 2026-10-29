<?php

namespace App\Modules\GptSandbox\Application;

/** Усі розрахунки виконуються цілими копійками. */
final class Money
{
    public static function cents(string $amount): int
    {
        if (! preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', $amount)) {
            throw new \DomainException('Товар не має коректної ціни. Потрібне уточнення магазину.');
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function display(int $cents): string
    {
        return $cents % 100 === 0 ? (string) intdiv($cents, 100) : str_replace('.', ',', self::decimal($cents));
    }

    public static function label(?string $price): string
    {
        try {
            $cents = self::cents((string) $price);

            return $cents > 0 ? self::display($cents).' грн' : 'ціну уточнюйте';
        } catch (\DomainException) {
            return 'ціну уточнюйте';
        }
    }
}
