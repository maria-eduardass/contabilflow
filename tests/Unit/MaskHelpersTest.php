<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MaskHelpersTest extends TestCase
{
    public function testCnpjMask(): void
    {
        $this->assertSame('11.222.333/0001-81', cnpj_mask('11222333000181'));
    }

    public function testCnpjMaskLeavesOtherFormatsUntouched(): void
    {
        $this->assertSame('123', cnpj_mask('123'));
        $this->assertSame('', cnpj_mask(''));
    }

    public function testPhoneMaskMobileAndLandline(): void
    {
        $this->assertSame('(11) 98765-4321', phone_mask('5511987654321'));
        $this->assertSame('(11) 3456-7890', phone_mask('551134567890'));
    }

    public function testMoneyBr(): void
    {
        $this->assertSame('R$ 1.234,50', money_br('1234.50'));
        $this->assertSame('R$ 0,00', money_br('0.00'));
        $this->assertSame('R$ 1.234.567,89', money_br('1234567.89'));
        $this->assertSame('—', money_br(null));
    }

    public function testDateBr(): void
    {
        $date = new \DateTimeImmutable('2026-11-05 10:30:00');

        $this->assertSame('05/11/2026', date_br($date));
        $this->assertSame('11/2026', date_br($date, 'm/Y'));
        $this->assertSame('—', date_br(null));
    }

    public function testPhoneMaskLeavesOtherFormatsUntouched(): void
    {
        $this->assertSame('abc', phone_mask('abc'));
    }
}
