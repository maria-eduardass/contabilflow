<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit\Validation;

use ContabilFlow\Validation\Rules\Whatsapp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WhatsappTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function validProvider(): array
    {
        return [
            'mobile with mask' => ['(11) 98765-4321', '5511987654321'],
            'mobile with country code and plus' => ['+55 11 98765-4321', '5511987654321'],
            'mobile digits only' => ['11987654321', '5511987654321'],
            'already normalized' => ['5511987654321', '5511987654321'],
            'landline' => ['(11) 3234-5678', '551132345678'],
            'landline with country code' => ['551132345678', '551132345678'],
            'DDD 55 mobile, 11 digits' => ['55999998888', '5555999998888'],
        ];
    }

    #[DataProvider('validProvider')]
    public function testNormalizesValidNumbers(string $input, string $expected): void
    {
        $this->assertSame($expected, Whatsapp::normalize($input));
    }

    /** @return array<string, array{string}> */
    public static function invalidProvider(): array
    {
        return [
            'too short' => ['123'],
            'empty' => [''],
            'DDD starting with zero' => ['(01) 98765-4321'],
            'DDD with zero second digit' => ['10987654321'],
            'mobile not starting with 9' => ['11887654321'],
            'landline starting with 9 and 10 digits' => ['1198765432'],
            'letters' => ['11 9876a-4321'],
            'too long' => ['551198765432100'],
            'other country code' => ['+1 415 555 2671'],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function testRejectsInvalidNumbers(string $input): void
    {
        $this->assertNull(Whatsapp::normalize($input));
    }

    public function testNormalizedValueSatisfiesDatabaseCheckPattern(): void
    {
        foreach (['(11) 98765-4321', '(11) 3234-5678'] as $input) {
            $this->assertMatchesRegularExpression('/^55[0-9]{10,11}$/', (string) Whatsapp::normalize($input));
        }
    }
}
