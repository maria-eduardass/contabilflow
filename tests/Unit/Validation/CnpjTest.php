<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit\Validation;

use ContabilFlow\Validation\Rules\Cnpj;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CnpjTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function validProvider(): array
    {
        return [
            'only digits' => ['11222333000181', '11222333000181'],
            'masked' => ['11.222.333/0001-81', '11222333000181'],
            'another valid' => ['11.444.777/0001-61', '11444777000161'],
            'third valid' => ['45723174000110', '45723174000110'],
        ];
    }

    #[DataProvider('validProvider')]
    public function testNormalizesValidCnpj(string $input, string $expected): void
    {
        $this->assertSame($expected, Cnpj::normalize($input));
    }

    /** @return array<string, array{string}> */
    public static function invalidProvider(): array
    {
        return [
            'wrong check digit' => ['11222333000182'],
            'wrong first check digit' => ['11222333000191'],
            'repeated digits' => ['11111111111111'],
            'all zeros' => ['00000000000000'],
            'too short' => ['1122233300018'],
            'too long' => ['112223330001811'],
            'letters' => ['abcdefghijklmn'],
            'valid digits plus trailing letter' => ['11.222.333/0001-81x'],
            'empty' => [''],
            'spaces inside' => ['11 222 333 0001 81'],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function testRejectsInvalidCnpj(string $input): void
    {
        $this->assertNull(Cnpj::normalize($input));
    }
}
