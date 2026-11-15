<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Core\Database;
use PDO;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    public function testDsnUsesUtf8mb4(): void
    {
        $this->assertSame(
            'mysql:host=db;port=3307;dbname=app;charset=utf8mb4',
            Database::dsn('db', 3307, 'app')
        );
    }

    public function testOptionsForceSafePreparedStatements(): void
    {
        $options = Database::OPTIONS;

        $this->assertSame(PDO::ERRMODE_EXCEPTION, $options[PDO::ATTR_ERRMODE]);
        $this->assertFalse($options[PDO::ATTR_EMULATE_PREPARES]);
        $this->assertSame(PDO::FETCH_ASSOC, $options[PDO::ATTR_DEFAULT_FETCH_MODE]);
    }

    public function testRequiredKeysCoverConnectionSettings(): void
    {
        $this->assertSame(
            ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'],
            Database::REQUIRED_KEYS
        );
    }
}
