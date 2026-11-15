<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit\Validation;

use ContabilFlow\Validation\Validator;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator();
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $rules
     * @return array<string, string>
     */
    private function errors(array $input, array $rules): array
    {
        return $this->validator->validate($input, $rules)->errors;
    }

    // ---- required / opcionais -------------------------------------------------

    public function testRequiredFailsForMissingEmptyAndWhitespace(): void
    {
        $rules = ['nome' => ['required']];

        $this->assertArrayHasKey('nome', $this->errors([], $rules));
        $this->assertArrayHasKey('nome', $this->errors(['nome' => ''], $rules));
        $this->assertArrayHasKey('nome', $this->errors(['nome' => "  \t "], $rules));
        $this->assertArrayHasKey('nome', $this->errors(['nome' => null], $rules));
    }

    public function testRequiredAcceptsZeroString(): void
    {
        $this->assertSame([], $this->errors(['valor' => '0'], ['valor' => ['required']]));
    }

    public function testOptionalEmptyFieldSkipsOtherRulesAndBecomesNull(): void
    {
        $result = $this->validator->validate(['email' => '  '], ['email' => ['email']]);

        $this->assertTrue($result->passes());
        $this->assertNull($result->data['email']);
    }

    public function testValuesAreTrimmed(): void
    {
        $result = $this->validator->validate(['nome' => '  Maria  '], ['nome' => ['required']]);

        $this->assertSame('Maria', $result->data['nome']);
    }

    public function testNonScalarInputIsRejected(): void
    {
        $this->assertArrayHasKey('nome', $this->errors(['nome' => ['a']], ['nome' => ['required']]));
        $this->assertArrayHasKey('nome', $this->errors(['nome' => ['a']], ['nome' => ['maxLength:5']]));
    }

    public function testIntegerInputIsAcceptedAsString(): void
    {
        $result = $this->validator->validate(['q1' => 5], ['q1' => ['required', 'in:1,2,3,4,5']]);

        $this->assertTrue($result->passes());
        $this->assertSame('5', $result->data['q1']);
    }

    // ---- email ----------------------------------------------------------------

    public function testEmailValidAndNormalizedToLowercase(): void
    {
        $result = $this->validator->validate(['email' => '  Ana@Exemplo.COM '], ['email' => ['required', 'email']]);

        $this->assertTrue($result->passes());
        $this->assertSame('ana@exemplo.com', $result->data['email']);
    }

    public function testEmailInvalid(): void
    {
        foreach (['ana', 'ana@', '@x.com', 'a b@x.com', 'ana@x'] as $value) {
            $this->assertArrayHasKey('email', $this->errors(['email' => $value], ['email' => ['email']]), $value);
        }
    }

    // ---- maxLength ------------------------------------------------------------

    public function testMaxLengthBoundary(): void
    {
        $rules = ['nome' => ['maxLength:10']];

        $this->assertSame([], $this->errors(['nome' => str_repeat('a', 10)], $rules));
        $this->assertArrayHasKey('nome', $this->errors(['nome' => str_repeat('a', 11)], $rules));
    }

    public function testMaxLengthCountsCharactersNotBytes(): void
    {
        $this->assertSame([], $this->errors(['nome' => 'ççççç'], ['nome' => ['maxLength:5']]));
    }

    // ---- in -------------------------------------------------------------------

    public function testInAcceptsOnlyListedValues(): void
    {
        $rules = ['regime' => ['in:mei,simples,presumido,real']];

        $this->assertSame([], $this->errors(['regime' => 'mei'], $rules));
        $this->assertArrayHasKey('regime', $this->errors(['regime' => 'MEI'], $rules));
        $this->assertArrayHasKey('regime', $this->errors(['regime' => 'outro'], $rules));
        $this->assertArrayHasKey('regime', $this->errors(['regime' => 'mei,simples'], $rules));
    }

    // ---- date -----------------------------------------------------------------

    public function testDateAcceptsRealCalendarDates(): void
    {
        $this->assertSame([], $this->errors(['d' => '2026-11-14'], ['d' => ['date']]));
        $this->assertSame([], $this->errors(['d' => '2028-02-29'], ['d' => ['date']]));
    }

    public function testDateRejectsInvalidDates(): void
    {
        foreach (['2026-02-30', '2027-02-29', '2026-13-01', '14/11/2026', '2026-1-1', '26-11-14', 'hoje'] as $value) {
            $this->assertArrayHasKey('d', $this->errors(['d' => $value], ['d' => ['date']]), $value);
        }
    }

    // ---- decimal --------------------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function validDecimals(): array
    {
        return [
            'dot' => ['1234.56', '1234.56'],
            'comma' => ['1234,56', '1234.56'],
            'integer padded' => ['10', '10.00'],
            'one decimal padded' => ['10,5', '10.50'],
            'zero' => ['0', '0.00'],
            'leading zeros' => ['007,5', '7.50'],
            'max integer digits' => ['9999999999.99', '9999999999.99'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('validDecimals')]
    public function testDecimalNormalizesToDotNotation(string $input, string $expected): void
    {
        $result = $this->validator->validate(['valor' => $input], ['valor' => ['decimal:2']]);

        $this->assertTrue($result->passes(), $input);
        $this->assertSame($expected, $result->data['valor']);
    }

    /** @return array<string, array{string}> */
    public static function invalidDecimals(): array
    {
        return [
            'negative' => ['-1'],
            'three decimals' => ['1,234'],
            'thousands separator' => ['1.234,56'],
            'letters' => ['abc'],
            'currency symbol' => ['R$ 10,00'],
            'too many integer digits' => ['10000000000'],
            'trailing separator' => ['10,'],
            'only separator' => [','],
            'scientific' => ['1e3'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDecimals')]
    public function testDecimalRejectsInvalidValues(string $input): void
    {
        $this->assertArrayHasKey('valor', $this->errors(['valor' => $input], ['valor' => ['decimal:2']]), $input);
    }

    public function testDecimalWithZeroPlacesAcceptsOnlyIntegers(): void
    {
        $this->assertSame([], $this->errors(['n' => '12'], ['n' => ['decimal:0']]));
        $this->assertArrayHasKey('n', $this->errors(['n' => '12,5'], ['n' => ['decimal:0']]));
    }

    // ---- cnpj / whatsapp ------------------------------------------------------

    public function testCnpjIsValidatedAndNormalized(): void
    {
        $result = $this->validator->validate(['cnpj' => '11.222.333/0001-81'], ['cnpj' => ['required', 'cnpj']]);

        $this->assertTrue($result->passes());
        $this->assertSame('11222333000181', $result->data['cnpj']);
    }

    public function testCnpjInvalidMessage(): void
    {
        $errors = $this->errors(['cnpj' => '11222333000182'], ['cnpj' => ['required', 'cnpj']]);

        $this->assertSame('CNPJ inválido', $errors['cnpj']);
    }

    public function testWhatsappIsValidatedAndNormalized(): void
    {
        $result = $this->validator->validate(['whatsapp' => '(11) 98765-4321'], ['whatsapp' => ['whatsapp']]);

        $this->assertTrue($result->passes());
        $this->assertSame('5511987654321', $result->data['whatsapp']);
    }

    public function testWhatsappInvalid(): void
    {
        $this->assertArrayHasKey('whatsapp', $this->errors(['whatsapp' => '123'], ['whatsapp' => ['whatsapp']]));
    }

    // ---- comportamento geral --------------------------------------------------

    public function testOnlyFirstFailingRulePerFieldIsReported(): void
    {
        $errors = $this->errors(['email' => ''], ['email' => ['required', 'email', 'maxLength:5']]);

        $this->assertSame(['email' => 'Campo obrigatório'], $errors);
    }

    public function testReportsErrorsForEveryInvalidField(): void
    {
        $errors = $this->errors(
            ['cnpj' => 'x', 'regime' => 'y', 'nome' => 'ok'],
            ['cnpj' => ['required', 'cnpj'], 'regime' => ['required', 'in:mei'], 'nome' => ['required']]
        );

        $this->assertSame(['cnpj', 'regime'], array_keys($errors));
    }

    public function testFieldsWithoutRulesNeverReachValidatedData(): void
    {
        $result = $this->validator->validate(['nome' => 'A', 'perfil' => 'admin'], ['nome' => ['required']]);

        $this->assertSame(['nome' => 'A'], $result->data);
    }

    public function testFailsAndPassesAreOpposites(): void
    {
        $ok = $this->validator->validate(['nome' => 'A'], ['nome' => ['required']]);
        $bad = $this->validator->validate([], ['nome' => ['required']]);

        $this->assertTrue($ok->passes());
        $this->assertFalse($ok->fails());
        $this->assertTrue($bad->fails());
        $this->assertFalse($bad->passes());
    }

    public function testUnknownRuleIsAProgrammerError(): void
    {
        $this->expectException(LogicException::class);

        $this->validator->validate(['a' => 'x'], ['a' => ['inexistente']]);
    }

    public function testInvalidFieldKeepsOutOfValidatedData(): void
    {
        $result = $this->validator->validate(['cnpj' => 'x'], ['cnpj' => ['cnpj']]);

        $this->assertArrayNotHasKey('cnpj', $result->data);
    }
}
