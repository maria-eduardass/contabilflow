<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit\Validation;

use ContabilFlow\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorEncodingTest extends TestCase
{
    private function error(string $value, bool $required = false): ?string
    {
        $rules = $required ? ['required', 'maxLength:50'] : ['maxLength:50'];

        return (new Validator())->validate(['c' => $value], ['c' => $rules])->errors['c'] ?? null;
    }

    public function testRejectsBytesThatAreNotValidUtf8(): void
    {
        // "semáforo" em Windows-1252: o á vira o byte solto 0xE1.
        $this->assertSame('Texto com caracteres inválidos', $this->error("sem\xE1foro"));
        $this->assertSame('Texto com caracteres inválidos', $this->error("\xFF\xFE"));
        $this->assertSame('Texto com caracteres inválidos', $this->error("truncado \xC3"));
    }

    public function testRejectsInvalidUtf8EvenInRequiredFields(): void
    {
        $this->assertSame('Texto com caracteres inválidos', $this->error("nome\xE1", true));
    }

    public function testAcceptsAccentsEmojiAndOtherScripts(): void
    {
        foreach (['semáforo ação', 'Olá 👋', 'Ünïcödé', '日本語', 'Ω ≈ ç'] as $text) {
            $this->assertNull($this->error($text), $text);
        }
    }

    public function testInvalidTextDoesNotLeakIntoTheValidData(): void
    {
        $result = (new Validator())->validate(['c' => "x\xE1"], ['c' => ['maxLength:50']]);

        $this->assertArrayNotHasKey('c', $result->data);
        $this->assertTrue($result->fails());
    }
}
