<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Integration;

use ContabilFlow\Core\Database;
use ContabilFlow\Core\Env;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class DatabaseConnectionTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $path = __DIR__ . '/../../.env';
        if (!is_file($path)) {
            $this->markTestSkipped('Crie o .env a partir do .env.example para rodar a integração.');
        }

        // Por quê: testes nunca tocam o banco principal, só o DB_NAME_TEST.
        $env = Env::fromFile($path, [...Database::REQUIRED_KEYS, 'DB_NAME_TEST']);
        $this->pdo = Database::connect($env, 'DB_NAME_TEST');
    }

    public function testConnectsAndRunsPreparedQuery(): void
    {
        $stmt = $this->pdo->prepare('SELECT ? + 1 AS n');
        $stmt->execute([1]);

        $row = $stmt->fetch();
        $this->assertIsArray($row);
        $this->assertSame(2, (int) $row['n']);
    }

    public function testUsesUtf8mb4(): void
    {
        $stmt = $this->pdo->query('SELECT @@character_set_client AS c');
        $this->assertNotFalse($stmt);
        $row = $stmt->fetch();
        $this->assertIsArray($row);

        $this->assertSame('utf8mb4', $row['c']);
    }

    public function testErrorsThrowExceptions(): void
    {
        $this->expectException(PDOException::class);

        $this->pdo->query('SELECT * FROM tabela_que_nao_existe');
    }
}
