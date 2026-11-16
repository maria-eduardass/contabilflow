<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Integration;

use ContabilFlow\Core\LazyPdo;
use ContabilFlow\Core\Migrator;
use ContabilFlow\Models\LembreteCanal;
use ContabilFlow\Models\LembreteRepository;
use ContabilFlow\Tests\Support\TestDatabase;
use DateTimeImmutable;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class LembreteRepositoryTest extends TestCase
{
    private PDO $pdo;
    private LembreteRepository $repo;
    private int $guia;
    private int $ana;
    private int $bia;

    protected function setUp(): void
    {
        $pdo = TestDatabase::connectOrNull();
        if ($pdo === null) {
            $this->markTestSkipped('Crie o .env a partir do .env.example para rodar a integração.');
        }
        $this->pdo = $pdo;
        TestDatabase::reset($this->pdo);
        (new Migrator($this->pdo, __DIR__ . '/../../database/migrations'))->run();

        $this->pdo->exec(
            "INSERT INTO usuarios (nome, email, senha_hash, perfil) VALUES "
            . "('Ana', 'a@x.com', 'h', 'contador'), ('Bia', 'b@x.com', 'h', 'contador')"
        );
        $this->ana = (int) $this->pdo->lastInsertId();
        $this->bia = $this->ana + 1;
        $this->pdo->exec("INSERT INTO empresas (razao_social, cnpj, regime) VALUES ('Alfa', '11222333000181', 'mei')");
        $empresa = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO obrigacoes (empresa_id, tipo, competencia, vencimento, criado_por) "
            . "VALUES (?, 'DAS', '2026-10-01', '2026-11-20', ?)"
        )->execute([$empresa, $this->ana]);
        $this->guia = (int) $this->pdo->lastInsertId();

        $this->repo = new LembreteRepository(new LazyPdo(fn(): PDO => $pdo));
    }

    private function rowCount(string $table): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM ' . $table);

        return $stmt === false ? -1 : (int) $stmt->fetchColumn();
    }

    private function at(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when);
    }

    public function testCreateAndListWithUserNameAndAllFields(): void
    {
        $id = $this->repo->create($this->guia, $this->ana, LembreteCanal::Whatsapp, "Olá!\nVence amanhã.", $this->at('2026-11-16 10:30:00'));

        $items = $this->repo->listByObrigacao($this->guia);

        $this->assertCount(1, $items);
        $this->assertSame($id, $items[0]->id);
        $this->assertSame($this->guia, $items[0]->obrigacaoId);
        $this->assertSame($this->ana, $items[0]->usuarioId);
        $this->assertSame('Ana', $items[0]->usuarioNome);
        $this->assertSame(LembreteCanal::Whatsapp, $items[0]->canal);
        $this->assertSame("Olá!\nVence amanhã.", $items[0]->mensagem);
        $this->assertSame('2026-11-16 10:30:00', $items[0]->criadoEm->format('Y-m-d H:i:s'));
    }

    public function testListIsNewestFirstAndRespectsLimit(): void
    {
        $this->repo->create($this->guia, $this->ana, LembreteCanal::Copiado, 'primeiro', $this->at('2026-11-14 09:00:00'));
        $this->repo->create($this->guia, $this->bia, LembreteCanal::Email, 'segundo', $this->at('2026-11-15 09:00:00'));
        $this->repo->create($this->guia, $this->ana, LembreteCanal::Whatsapp, 'terceiro', $this->at('2026-11-16 09:00:00'));

        $all = $this->repo->listByObrigacao($this->guia);
        $limited = $this->repo->listByObrigacao($this->guia, 2);

        $this->assertSame(['terceiro', 'segundo', 'primeiro'], array_map(static fn($l): string => $l->mensagem, $all));
        $this->assertSame(['terceiro', 'segundo'], array_map(static fn($l): string => $l->mensagem, $limited));
        $this->assertSame('Bia', $all[1]->usuarioNome);
    }

    public function testSameTimestampKeepsInsertionOrderNewestFirst(): void
    {
        $when = $this->at('2026-11-16 09:00:00');
        $this->repo->create($this->guia, $this->ana, LembreteCanal::Copiado, 'a', $when);
        $this->repo->create($this->guia, $this->ana, LembreteCanal::Copiado, 'b', $when);

        $this->assertSame(['b', 'a'], array_map(static fn($l): string => $l->mensagem, $this->repo->listByObrigacao($this->guia)));
    }

    public function testListOfAnotherGuideOrEmptyReturnsNothing(): void
    {
        $this->repo->create($this->guia, $this->ana, LembreteCanal::Copiado, 'x', $this->at('2026-11-16 09:00:00'));

        $this->assertSame([], $this->repo->listByObrigacao($this->guia + 100));
    }

    public function testMessageKeepsAccentsAndEmojiAndSqlText(): void
    {
        $text = "Olá 👋 ação — R\$ 1.234,50; DROP TABLE lembretes; --";
        $this->repo->create($this->guia, $this->ana, LembreteCanal::Email, $text, $this->at('2026-11-16 09:00:00'));

        $this->assertSame($text, $this->repo->listByObrigacao($this->guia)[0]->mensagem);
        $this->assertSame(1, $this->rowCount('lembretes'));
    }

    public function testCreateForUnknownGuideViolatesTheForeignKey(): void
    {
        $this->expectException(PDOException::class);
        $this->repo->create($this->guia + 100, $this->ana, LembreteCanal::Email, 'x', $this->at('2026-11-16 09:00:00'));
    }

    public function testDeletingTheGuideDeletesItsHistory(): void
    {
        $this->repo->create($this->guia, $this->ana, LembreteCanal::Email, 'x', $this->at('2026-11-16 09:00:00'));

        $this->pdo->exec('DELETE FROM obrigacoes');

        $this->assertSame(0, $this->rowCount('lembretes'));
    }

    public function testUserWithHistoryCannotBeDeleted(): void
    {
        $this->repo->create($this->guia, $this->bia, LembreteCanal::Email, 'x', $this->at('2026-11-16 09:00:00'));

        $this->expectException(PDOException::class);
        $this->pdo->exec("DELETE FROM usuarios WHERE nome = 'Bia'");
    }
}
