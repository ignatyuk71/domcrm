<?php

namespace App\Support;

class EmailNormalizer
{
    /** Прибирає пробіли, зокрема нерозривні, після введення або копіювання адреси. */
    public static function normalize(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $normalized = preg_replace('/[\s\p{Z}]+/u', '', $email) ?? $email;

        return $normalized !== '' ? $normalized : null;
    }
}
