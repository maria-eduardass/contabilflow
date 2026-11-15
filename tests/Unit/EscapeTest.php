<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class EscapeTest extends TestCase
{
    public function testEscapesScriptTag(): void
    {
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', e('<script>alert(1)</script>'));
    }

    public function testEscapesBothKindsOfQuotesAndAmpersand(): void
    {
        $this->assertSame('&quot;a&quot; &#039;b&#039; &amp; c', e('"a" \'b\' & c'));
    }

    public function testKeepsUtf8(): void
    {
        $this->assertSame('ação – São Paulo', e('ação – São Paulo'));
    }

    public function testInvalidUtf8IsSubstitutedNotEmptied(): void
    {
        $this->assertNotSame('', e("abc\xFF"));
    }

    public function testAcceptsNullAndNumbers(): void
    {
        $this->assertSame('', e(null));
        $this->assertSame('42', e(42));
        $this->assertSame('1.5', e(1.5));
    }
}
