<?php

namespace Tests\Unit;

use App\Support\EmailNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EmailNormalizerTest extends TestCase
{
    #[DataProvider('emailCases')]
    public function test_normalizes_email(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, EmailNormalizer::normalize($input));
    }

    public static function emailCases(): array
    {
        return [
            'порожнє значення' => [null, null],
            'порожній рядок' => ['', null],
            'лише пробіли' => [" \t\u{00A0}\n", null],
            'звичайна адреса' => ['buyer@example.test', 'buyer@example.test'],
            'Gmail з крапкою і плюсом' => ['Buyer.Test+shop@gmail.com', 'Buyer.Test+shop@gmail.com'],
            'Googlemail' => ['buyer@googlemail.com', 'buyer@googlemail.com'],
            'пробіли' => [" buyer\u{00A0} @\tgmail.com\n", 'buyer@gmail.com'],
            'без собачки' => ['buyer.gmail.com', null],
            'крапка перед собачкою' => ['buyer.@gmail.com', null],
            'крапка на початку' => ['.buyer@gmail.com', null],
            'подвійна крапка' => ['buyer..test@gmail.com', null],
            'незавершений домен' => ['buyer@gmail', null],
            'описка домену Gmail' => ['buyer@gmail.com.ua', null],
            'описка без урахування регістру' => ['buyer@GMAIL.COM.UA', null],
            'надто довга адреса' => [str_repeat('a', 256).'@gmail.com', null],
        ];
    }
}
