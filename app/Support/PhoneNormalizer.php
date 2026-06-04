<?php

namespace App\Support;

class PhoneNormalizer
{
    /**
     * Нормалізує телефон до цифрового вигляду у форматі 380XXXXXXXXX (де можливо).
     * Використовується для дедуплікації клієнтів між сайтами та ручним вводом.
     */
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        // Прибираємо міжнародний префікс "00"
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $len = strlen($digits);

        // 0XXXXXXXXX (10 цифр) -> 380XXXXXXXXX
        if ($len === 10 && str_starts_with($digits, '0')) {
            return '38' . $digits;
        }

        // XXXXXXXXX (9 цифр, без 0) -> 380XXXXXXXXX
        if ($len === 9) {
            return '380' . $digits;
        }

        // 80XXXXXXXXX (11 цифр) -> 380XXXXXXXXX
        if ($len === 11 && str_starts_with($digits, '80')) {
            return '3' . $digits;
        }

        return $digits;
    }
}
