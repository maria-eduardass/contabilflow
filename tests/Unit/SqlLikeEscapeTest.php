<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Core\Sql;
use PHPUnit\Framework\TestCase;

final class SqlLikeEscapeTest extends TestCase
{
    public function testPlainTextIsUnchanged(): void
    {
        $this->assertSame('padaria', Sql::escapeLike('padaria'));
    }

    public function testWildcardsAreEscaped(): void
    {
        $this->assertSame('100!%', Sql::escapeLike('100%'));
        $this->assertSame('a!_b', Sql::escapeLike('a_b'));
    }

    public function testEscapeCharacterItselfIsEscapedFirst(): void
    {
        $this->assertSame('a!!b', Sql::escapeLike('a!b'));
        $this->assertSame('!!!%', Sql::escapeLike('!%'));
    }

    public function testBackslashIsNotSpecial(): void
    {
        $this->assertSame('a\\b', Sql::escapeLike('a\\b'));
    }
}
