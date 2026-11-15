<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Integration;

use ContabilFlow\Core\Migrator;
use ContabilFlow\Core\Seeder;
use ContabilFlow\Tests\Support\TestDatabase;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SeederTest extends TestCase
{
    private const ADMIN_PASSWORD = 'admin-senha-123';
    private const CONTADOR_PASSWORD = 'contador-senha-123';

    private PDO $pdo;
    private DateTimeImmutable $today;

    protected function setUp(): void
    {
        $pdo = TestDatabase::connectOrNull();
        if ($pdo === null) {
            $this->markTestSkipped('Crie o .env a partir do .env.example para rodar a integração.');
        }
        $this->pdo = $pdo;
        $this->today = new DateTimeImmutable('2026-11-14');
        TestDatabase::reset($this->pdo);
        (new Migrator($this->pdo, __DIR__ . '/../../database/migrations'))->run();
    }

    private function seed(): void
    {
        (new Seeder($this->pdo))->run($this->today, self::ADMIN_PASSWORD, self::CONTADOR_PASSWORD);
    }

    private function scalar(string $sql, mixed ...$params): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function testCreatesAdminAndContadorWithHashedPasswords(): void
    {
        $this->seed();

        $stmt = $this->pdo->query('SELECT perfil, senha_hash FROM usuarios ORDER BY id');
        $users = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->assertSame(['admin', 'contador'], array_keys($users));
        $this->assertTrue(password_verify(self::ADMIN_PASSWORD, $users['admin']));
        $this->assertTrue(password_verify(self::CONTADOR_PASSWORD, $users['contador']));
        $this->assertNotSame(self::ADMIN_PASSWORD, $users['admin']);
    }

    public function testCreatesThreeCompanies(): void
    {
        $this->seed();

        $this->assertSame(3, $this->scalar('SELECT COUNT(*) FROM empresas'));
    }

    public function testCoversEveryTrafficLightColor(): void
    {
        $this->seed();
        $open = "status IN ('pendente','enviada')";
        $today = $this->today->format('Y-m-d');

        $this->assertGreaterThan(0, $this->scalar("SELECT COUNT(*) FROM obrigacoes WHERE {$open} AND vencimento < ?", $today));
        $this->assertGreaterThan(0, $this->scalar("SELECT COUNT(*) FROM obrigacoes WHERE {$open} AND DATEDIFF(vencimento, ?) BETWEEN 0 AND 2", $today));
        $this->assertGreaterThan(0, $this->scalar("SELECT COUNT(*) FROM obrigacoes WHERE {$open} AND DATEDIFF(vencimento, ?) BETWEEN 3 AND 7", $today));
        $this->assertGreaterThan(0, $this->scalar("SELECT COUNT(*) FROM obrigacoes WHERE {$open} AND DATEDIFF(vencimento, ?) > 7", $today));
        $this->assertGreaterThan(0, $this->scalar("SELECT COUNT(*) FROM obrigacoes WHERE status = 'paga'"));
        $this->assertGreaterThan(0, $this->scalar("SELECT COUNT(*) FROM obrigacoes WHERE status = 'cancelada'"));
    }

    public function testCompaniesHaveWhatsappForReminders(): void
    {
        $this->seed();

        $this->assertGreaterThan(0, $this->scalar('SELECT COUNT(*) FROM empresas WHERE whatsapp IS NOT NULL'));
    }

    public function testRefusesToSeedTwice(): void
    {
        $this->seed();

        $this->expectException(RuntimeException::class);
        $this->seed();
    }

    public function testRejectsShortPasswords(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Seeder($this->pdo))->run($this->today, 'curta', self::CONTADOR_PASSWORD);
    }
}
