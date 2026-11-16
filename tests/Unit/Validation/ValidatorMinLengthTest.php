<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit\Validation;

use ContabilFlow\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorMinLengthTest extends TestCase
{
    private function error(string $value, string $rule = 'minLength:5'): ?string
    {
        return (new Validator())->validate(['c' => $value], ['c' => ['required', $rule]])->errors['c'] ?? null;
    }

    public function testRejectsValuesShorterThanTheMinimum(): void
    {
        $this->assertSame('Mínimo de 5 caracteres', $this->error('abcd'));
    }

    public function testAcceptsExactlyTheMinimumAndLonger(): void
    {
        $this->assertNull($this->error('abcde'));
        $this->assertNull($this->error('abcdefgh'));
    }

    public function testCountsCharactersNotBytes(): void
    {
        $this->assertNull($this->error('ações')); // 5 caracteres, 8 bytes
        $this->assertSame('Mínimo de 5 caracteres', $this->error('ação'));
    }

    public function testOptionalEmptyValueSkipsTheRule(): void
    {
        $result = (new Validator())->validate(['c' => ''], ['c' => ['minLength:5']]);

        $this->assertTrue($result->passes());
    }
}
