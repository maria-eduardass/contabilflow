<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Integration;

use ContabilFlow\Core\Migrator;
use ContabilFlow\Tests\Support\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $pdo = TestDatabase::connectOrNull();
        if ($pdo === null) {
            $this->markTestSkipped('Crie o .env a partir do .env.example para rodar a integração.');
        }
        $this->pdo = $pdo;
        TestDatabase::reset($this->pdo);
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->pdo, __DIR__ . '/../../database/migrations');
    }

    public function testAppliesAllMigrationsInOrder(): void
    {
        $applied = $this->migrator()->run();

        $this->assertSame(
            ['001_usuarios.sql', '002_empresas.sql', '003_obrigacoes.sql', '004_lembretes.sql', '005_avaliacoes.sql'],
            $applied
        );
        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['usuarios', 'empresas', 'obrigacoes', 'lembretes', 'avaliacoes', 'schema_migrations'] as $t) {
            $this->assertContains($t, $tables);
        }
    }

    public function testSecondRunAppliesNothing(): void
    {
        $this->migrator()->run();

        $this->assertSame([], $this->migrator()->run());
    }

    public function testRecordsAppliedMigrations(): void
    {
        $this->migrator()->run();

        $count = $this->pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
        $this->assertSame(5, (int) $count);
    }

    public function testFailsWhenMigrationDirectoryIsMissing(): void
    {
        $this->expectException(\RuntimeException::class);

        (new Migrator($this->pdo, __DIR__ . '/nao-existe'))->run();
    }
}
