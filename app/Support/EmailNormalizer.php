<?php

namespace App\Support;

class EmailNormalizer
{
    /** Прибирає пробіли; некоректну адресу зберігаємо як порожнє поле. */
    public static function normalize(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $normalized = preg_replace('/[\s\p{Z}]+/u', '', $email) ?? $email;

        if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        // Цей домен є помилкою введення Gmail; адресу не виправляємо навмання.
        $domain = strtolower(substr($normalized, strrpos($normalized, '@') + 1));

        return $domain === 'gmail.com.ua' ? null : $normalized;
    }
}
