<?php

namespace App\Services\Orders;

use App\Support\PhoneNormalizer;
use Illuminate\Database\Eloquent\Builder;

final class OrderSearch
{
    private const INVISIBLE_CHARACTERS = [
        "\u{200B}" => '', "\u{200E}" => '', "\u{200F}" => '', "\u{2060}" => '', "\u{FEFF}" => '',
    ];

    private const APOSTROPHES = ["\u{2018}" => "'", "\u{2019}" => "'", "\u{02BC}" => "'"];

    private const DASHES = [
        "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-',
        "\u{2013}" => '-', "\u{2014}" => '-', "\u{2015}" => '-', "\u{2212}" => '-',
    ];

    public function apply(Builder $query, string $term): void
    {
        $term = strtr($term, self::INVISIBLE_CHARACTERS + self::APOSTROPHES + self::DASHES);
        $term = trim(preg_replace('/\s+/u', ' ', $term) ?? $term);
        if ($term === '') {
            return;
        }

        $tokens = $this->tokens($term);
        $query->where(function (Builder $search) use ($tokens) {
            foreach ($tokens as $token) {
                $search->where(fn (Builder $match) => $this->matchToken($match, $token));
            }
        });
    }

    private function tokens(string $term): array
    {
        $parts = explode(' ', $term);
        $tokens = [];
        $number = '';
        foreach ($parts as $index => $part) {
            // Позначення номера, скопійоване з інтерфейсу, не є частиною самого номера.
            if (in_array($part, ['#', '№'], true) && isset($parts[$index + 1])) {
                continue;
            }
            $part = preg_replace('/^[#№](?=[\p{L}\p{N}])/u', '', $part) ?? $part;

            // Сусідні числові частини залишаються одним фрагментом і в змішаному запиті.
            if (preg_match('/^[0-9+().-]+$/', $part) === 1) {
                $number .= $part;

                continue;
            }
            if ($number !== '') {
                $tokens[] = $number;
                $number = '';
            }
            $tokens[] = $part;
        }
        if ($number !== '') {
            $tokens[] = $number;
        }

        return array_values(array_unique($tokens));
    }

    private function matchToken(Builder $query, string $token): void
    {
        $digits = preg_match('/^[0-9+().-]+$/', $token) === 1 ? preg_replace('/[^0-9]/', '', $token) : '';
        $phoneVariants = $digits !== '' ? $this->exactPhoneVariants($digits) : [];
        $phonePatterns = $digits !== '' ? $this->canonicalPhonePatterns($digits) : [];

        $this->contains($query, 'order_number', $token);
        if ($digits !== '') {
            $query->orWhere(fn (Builder $number) => $this->containsDigits($number, 'order_number', $digits));
        }

        $query->orWhereHas('customer', function (Builder $customer) use ($token, $digits, $phoneVariants, $phonePatterns) {
            $this->contains($customer, 'first_name', $token);
            $this->contains($customer, 'last_name', $token, 'or');
            $this->contains($customer, 'email', $token, 'or');
            $this->contains($customer, 'phone', $token, 'or');

            if ($digits !== '') {
                // phone_normalized може містити старий контакт; джерело істини — поточний phone.
                $customer->orWhere(fn (Builder $phone) => $this->containsDigits($phone, 'phone', $digits, $phoneVariants, $phonePatterns));
            }
        })->orWhereHas('delivery', function (Builder $delivery) use ($token, $digits, $phoneVariants, $phonePatterns) {
            $this->contains($delivery, 'ttn', $token);
            $this->contains($delivery, 'recipient_name', $token, 'or');
            $this->contains($delivery, 'recipient_phone', $token, 'or');

            if ($digits !== '') {
                $delivery->orWhere(fn (Builder $ttn) => $this->containsDigits($ttn, 'ttn', $digits))
                    ->orWhere(fn (Builder $phone) => $this->containsDigits($phone, 'recipient_phone', $digits, $phoneVariants, $phonePatterns));
            }
        })->orWhere(function (Builder $legacy) use ($token) {
            // Знімок контактів використовуємо лише коли клієнта вже видалено.
            $legacy->whereNull('customer_id');
            $this->contains($legacy, 'search_blob', $token);
        });
    }

    private function exactPhoneVariants(string $digits): array
    {
        $normalized = PhoneNormalizer::normalize($digits);
        if (strlen($digits) >= 10 && preg_match('/^380[0-9]{9}$/', $normalized) === 1) {
            $variants = [$normalized, substr($normalized, 1), substr($normalized, 2), substr($normalized, 3)];

            return array_merge($variants, array_map(fn (string $variant) => '00'.$variant, $variants));
        }

        // Міжнародний префікс 00 прибираємо лише для повного номера, зберігаючи нулі фрагментів.
        return strlen($digits) >= 10 && str_starts_with($digits, '00') ? [$normalized] : [];
    }

    private function contains(Builder $query, string $column, string $term, string $boolean = 'and'): void
    {
        $term = mb_strtolower($term, 'UTF-8');
        $replacements = self::INVISIBLE_CHARACTERS;
        if (str_contains($term, "'")) {
            $replacements += self::APOSTROPHES;
        }
        if (str_contains($term, '-')) {
            $replacements += self::DASHES;
        }

        // SQLite LOWER підтримує лише ASCII; враховуємо регістр кирилиці й у локальній БД.
        if ($query->getConnection()->getDriverName() === 'sqlite') {
            foreach (array_unique(mb_str_split($term)) as $char) {
                $upper = mb_strtoupper($char, 'UTF-8');
                if (strlen($char) > 1 && $upper !== $char) {
                    $replacements[$upper] = $char;
                }
            }
        }

        [$sql, $bindings] = $this->expression($query, $column, $replacements);
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
        $query->whereRaw('LOWER('.$sql.") LIKE ? ESCAPE '!'", [...$bindings, $pattern], $boolean);
    }

    private function canonicalPhonePatterns(string $digits): array
    {
        // Локальні 0XXXXXXXXX і XXXXXXXXX також мають знаходитися за фрагментом 380XXXXXXXXX.
        // Фіксована довжина й початок відрізняють ці формати від телефонів інших країн.
        for ($offset = 0; $offset < 3; $offset++) {
            $prefix = substr('380', $offset);
            if (str_starts_with($prefix, $digits)) {
                $suffix = '';
            } elseif (str_starts_with($digits, $prefix)) {
                $suffix = substr($digits, strlen($prefix));
            } else {
                continue;
            }
            if (strlen($suffix) > 9) {
                continue;
            }

            $pattern = $suffix.str_repeat('_', 9 - strlen($suffix));

            return array_map(fn (string $localPrefix) => $localPrefix.$pattern, ['', '0', '80', '00', '000', '0080']);
        }

        return [];
    }

    private function containsDigits(Builder $query, string $column, string $digits, array $exactVariants = [], array $phonePatterns = []): void
    {
        $separators = ['+', ' ', '(', ')', '-', '.', "\t", "\r", "\n", "\u{00A0}", "\u{202F}"];
        $replacements = array_fill_keys($separators, '') + array_fill_keys(array_keys(self::DASHES), '') + self::INVISIBLE_CHARACTERS;
        [$sql, $bindings] = $this->expression($query, $column, $replacements);

        $query->where(function (Builder $numbers) use ($sql, $bindings, $digits, $exactVariants, $phonePatterns) {
            // Довільний набір цифр завжди шукаємо як безперервний фрагмент.
            $numbers->whereRaw($sql.' LIKE ?', [...$bindings, '%'.$digits.'%']);
            if ($exactVariants) {
                // Альтернативні формати повного телефону порівнюємо точно, без збігів чужого суфікса.
                $placeholders = implode(', ', array_fill(0, count($exactVariants), '?'));
                $numbers->orWhereRaw($sql.' IN ('.$placeholders.')', [...$bindings, ...$exactVariants]);
            }
            if ($phonePatterns) {
                $numbers->orWhere(function (Builder $canonical) use ($sql, $bindings, $phonePatterns) {
                    // Порожні та текстові заглушки не є дев'ятизначними локальними телефонами.
                    $digitCheck = $canonical->getConnection()->getDriverName() === 'sqlite'
                        ? " NOT GLOB '*[^0-9]*'"
                        : " REGEXP '^[0-9]+$'";
                    $canonical->whereRaw($sql.$digitCheck, $bindings)
                        ->where(function (Builder $patterns) use ($sql, $bindings, $phonePatterns) {
                            foreach ($phonePatterns as $pattern) {
                                $patterns->orWhereRaw($sql.' LIKE ?', [...$bindings, $pattern]);
                            }
                        });
                });
            }
        });
    }

    /** Усі значення передаємо параметрами; REPLACE сумісний із MySQL і SQLite. */
    private function expression(Builder $query, string $column, array $replacements): array
    {
        $sql = $query->getQuery()->getGrammar()->wrap($column);
        $bindings = [];
        foreach ($replacements as $from => $to) {
            $sql = 'REPLACE('.$sql.', ?, ?)';
            array_push($bindings, $from, $to);
        }

        return [$sql, $bindings];
    }
}
