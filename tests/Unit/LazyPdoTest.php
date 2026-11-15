<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Core\LazyPdo;
use PDO;
use PHPUnit\Framework\TestCase;

final class LazyPdoTest extends TestCase
{
    public function testDoesNotConnectUntilNeeded(): void
    {
        $calls = 0;

        new LazyPdo(function () use (&$calls): PDO {
            $calls++;

            return new PDO('sqlite::memory:');
        });

        $this->assertSame(0, $calls);
    }

    public function testConnectsOnceAndReusesConnection(): void
    {
        $calls = 0;
        $lazy = new LazyPdo(function () use (&$calls): PDO {
            $calls++;

            return new PDO('sqlite::memory:');
        });

        $this->assertSame($lazy->pdo(), $lazy->pdo());
        $this->assertSame(1, $calls);
    }
}
