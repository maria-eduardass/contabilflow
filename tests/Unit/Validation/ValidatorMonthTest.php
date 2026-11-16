<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit\Validation;

use ContabilFlow\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidatorMonthTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function invalid(): array
    {
        return [
            'mês 13' => ['2026-13'],
            'mês 00' => ['2026-00'],
            'sem zero' => ['2026-1'],
            'dia junto' => ['2026-10-15'],
            'barra' => ['10/2026'],
            'ano curto' => ['26-10'],
            'texto' => ['outubro'],
        ];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidMonths(string $value): void
    {
        $result = (new Validator())->validate(['competencia' => $value], ['competencia' => ['required', 'month']]);

        $this->assertSame('Mês inválido (use AAAA-MM)', $result->errors['competencia'] ?? null);
    }

    public function testAcceptsValidMonthAndNormalizesToFirstDay(): void
    {
        $validator = new Validator();

        $this->assertSame('2026-10-01', $validator->validate(['c' => '2026-10'], ['c' => ['month']])->data['c']);
        $this->assertSame('2026-01-01', $validator->validate(['c' => ' 2026-01 '], ['c' => ['month']])->data['c']);
        $this->assertSame('2026-12-01', $validator->validate(['c' => '2026-12'], ['c' => ['month']])->data['c']);
    }

    public function testOptionalEmptyMonthBecomesNull(): void
    {
        $result = (new Validator())->validate(['c' => ''], ['c' => ['month']]);

        $this->assertTrue($result->passes());
        $this->assertNull($result->data['c']);
    }
}
