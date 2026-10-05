<?php

namespace App\Services\NovaPay;

class Money
{
    public static function minor(string $value): int
    {
        if (! preg_match('/^(-?)(\d{1,14})(?:\.(\d{1,4}))?$/D', trim($value), $parts)) {
            throw new NovaPayException('invalid_amount', 'NovaPay повернув некоректну грошову суму.');
        }
        $fraction = $parts[3] ?? '';
        if (trim(substr($fraction, 2), '0') !== '') {
            throw new NovaPayException('invalid_amount', 'Сума NovaPay містить частину копійки. Потрібна перевірка формату.');
        }
        $minor = ((int) $parts[2]) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $parts[1] === '-' ? -$minor : $minor;
    }
}
