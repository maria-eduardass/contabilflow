<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Integration;

use ContabilFlow\Core\LazyPdo;
use ContabilFlow\Core\Migrator;
use ContabilFlow\Models\Avaliacao;
use ContabilFlow\Models\AvaliacaoDados;
use ContabilFlow\Models\AvaliacaoRepository;
use ContabilFlow\Tests\Support\TestDatabase;
use DateTimeImmutable;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class AvaliacaoRepositoryTest extends TestCase
{
    private PDO $pdo;
    private AvaliacaoRepository $repo;
    private int $ana;
    private int $admin;

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
            . "('Ana', 'a@x.com', 'h', 'contador'), ('Admin', 'adm@x.com', 'h', 'admin')"
        );
        $this->ana = (int) $this->pdo->lastInsertId();
        $this->admin = $this->ana + 1;
        $this->repo = new AvaliacaoRepository(new LazyPdo(fn(): PDO => $pdo));
    }

    private function resposta(int $q = 4, int $nps = 9, ?string $comentario = null): AvaliacaoDados
    {
        return new AvaliacaoDados($q, $q, $q, $q, $q, $nps, $comentario);
    }

    private function at(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when);
    }

    private function rowCount(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM avaliacoes');

        return $stmt === false ? -1 : (int) $stmt->fetchColumn();
    }

    // ---------- gravação ----------

    public function testCreateStoresEveryField(): void
    {
        $id = $this->repo->create(
            $this->ana,
            'contador',
            new AvaliacaoDados(5, 4, 3, 2, 1, 8, 'Ótimo 👍'),
            $this->at('2026-11-16 10:00:00')
        );

        $stmt = $this->pdo->query("SELECT * FROM avaliacoes WHERE id = {$id}");
        $this->assertNotFalse($stmt);
        $row = $stmt->fetch();
        $this->assertIsArray($row);
        $this->assertSame($this->ana, (int) $row['usuario_id']);
        $this->assertSame('contador', $row['perfil']);
        $this->assertSame([5, 4, 3, 2, 1], [(int) $row['q1'], (int) $row['q2'], (int) $row['q3'], (int) $row['q4'], (int) $row['q5']]);
        $this->assertSame(8, (int) $row['nps']);
        $this->assertSame('Ótimo 👍', $row['comentario']);
        $this->assertSame('2026-11-16 10:00:00', $row['criado_em']);
    }

    public function testNullCommentIsStoredAsNull(): void
    {
        $id = $this->repo->create($this->ana, 'contador', $this->resposta(), $this->at('2026-11-16 10:00:00'));

        $stmt = $this->pdo->query("SELECT comentario FROM avaliacoes WHERE id = {$id}");
        $this->assertNotFalse($stmt);
        $this->assertNull($stmt->fetchColumn());
    }

    /** O banco recusa valores fora da escala mesmo se a validação do PHP falhar. */
    public function testDatabaseRejectsAnswersOutsideTheScale(): void
    {
        foreach ([new AvaliacaoDados(0, 3, 3, 3, 3, 5, null), new AvaliacaoDados(3, 3, 3, 3, 6, 5, null)] as $dados) {
            try {
                $this->repo->create($this->ana, 'contador', $dados, $this->at('2026-11-16 10:00:00'));
                $this->fail('Nota fora de 1 a 5 deveria ser recusada');
            } catch (PDOException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([11, -1] as $nps) {
            try {
                $this->repo->create($this->ana, 'contador', $this->resposta(3, $nps), $this->at('2026-11-16 10:00:00'));
                $this->fail('NPS fora de 0 a 10 deveria ser recusado');
            } catch (PDOException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, $this->rowCount());
    }

    public function testDatabaseRejectsAnUnknownProfileAndLongComment(): void
    {
        try {
            $this->repo->create($this->ana, 'cliente', $this->resposta(), $this->at('2026-11-16 10:00:00'));
            $this->fail('Perfil inexistente deveria ser recusado');
        } catch (PDOException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(PDOException::class);
        $this->repo->create(
            $this->ana,
            'contador',
            $this->resposta(3, 5, str_repeat('a', Avaliacao::MAX_COMENTARIO + 1)),
            $this->at('2026-11-16 10:00:00')
        );
    }

    public function testDeletingTheUserKeepsTheEvaluationWithoutAuthor(): void
    {
        $this->repo->create($this->ana, 'contador', $this->resposta(5, 10, 'Bom'), $this->at('2026-11-16 10:00:00'));

        $this->pdo->exec("DELETE FROM usuarios WHERE nome = 'Ana'");

        $resumo = $this->repo->resumo();
        $this->assertSame(1, $resumo->geral->total);
        $this->assertNull($resumo->comentarios[0]->autor);
    }

    // ---------- resumo ----------

    public function testEmptySummary(): void
    {
        $resumo = $this->repo->resumo();

        $this->assertSame(0, $resumo->geral->total);
        $this->assertNull($resumo->geral->nps());
        $this->assertSame([], $resumo->porPerfil);
        $this->assertSame([], $resumo->comentarios);
    }

    public function testSummaryComputesAveragesAndNpsPerProfileAndOverall(): void
    {
        // contadores: notas 5 e 3 (média 4), NPS 10 e 7 (1 promotor, 1 neutro) => NPS 50
        $this->repo->create($this->ana, 'contador', $this->resposta(5, 10), $this->at('2026-11-16 09:00:00'));
        $this->repo->create($this->ana, 'contador', $this->resposta(3, 7), $this->at('2026-11-16 09:05:00'));
        // admin: nota 1, NPS 2 (detrator) => NPS -100
        $this->repo->create($this->admin, 'admin', $this->resposta(1, 2), $this->at('2026-11-16 09:10:00'));

        $resumo = $this->repo->resumo();

        $this->assertSame(['admin', 'contador'], array_keys($resumo->porPerfil));
        $contador = $resumo->porPerfil['contador'];
        $this->assertSame(2, $contador->total);
        $this->assertSame(4.0, $contador->medias['q1']);
        $this->assertSame(50, $contador->nps());
        $this->assertSame([1, 1, 0], [$contador->promotores, $contador->neutros, $contador->detratores]);

        $admin = $resumo->porPerfil['admin'];
        $this->assertSame(1, $admin->total);
        $this->assertSame(1.0, $admin->mediaGeral);
        $this->assertSame(-100, $admin->nps());

        $geral = $resumo->geral;
        $this->assertSame(3, $geral->total);
        $this->assertSame(3.0, $geral->medias['q1']);            // (5+3+1)/3
        $this->assertSame(0, $geral->nps());                      // (1 promotor - 1 detrator)/3
        $this->assertSame([1, 1, 1], [$geral->promotores, $geral->neutros, $geral->detratores]);
    }

    public function testNpsBoundariesNineIsPromoterSevenAndEightNeutralSixDetractor(): void
    {
        foreach ([9, 8, 7, 6] as $i => $nps) {
            $this->repo->create($this->ana, 'contador', $this->resposta(3, $nps), $this->at('2026-11-16 09:0' . $i . ':00'));
        }

        $stats = $this->repo->resumo()->geral;

        $this->assertSame([1, 2, 1], [$stats->promotores, $stats->neutros, $stats->detratores]);
    }

    public function testEachQuestionHasItsOwnAverage(): void
    {
        $this->repo->create($this->ana, 'contador', new AvaliacaoDados(5, 4, 3, 2, 1, 5, null), $this->at('2026-11-16 09:00:00'));
        $this->repo->create($this->ana, 'contador', new AvaliacaoDados(1, 2, 3, 4, 5, 5, null), $this->at('2026-11-16 09:01:00'));

        $medias = $this->repo->resumo()->geral->medias;

        $this->assertSame(['q1' => 3.0, 'q2' => 3.0, 'q3' => 3.0, 'q4' => 3.0, 'q5' => 3.0], $medias);
    }

    public function testCommentsAreNewestFirstSkipEmptyOnesAndCarryAuthorAndProfile(): void
    {
        $this->repo->create($this->ana, 'contador', $this->resposta(3, 5, 'primeiro'), $this->at('2026-11-14 09:00:00'));
        $this->repo->create($this->ana, 'contador', $this->resposta(3, 5, null), $this->at('2026-11-15 09:00:00'));
        $this->repo->create($this->ana, 'contador', $this->resposta(3, 5, ''), $this->at('2026-11-15 09:30:00'));
        $this->repo->create($this->admin, 'admin', $this->resposta(3, 9, 'terceiro'), $this->at('2026-11-16 09:00:00'));

        $comentarios = $this->repo->resumo()->comentarios;

        $this->assertSame(['terceiro', 'primeiro'], array_map(static fn($c): string => $c->comentario, $comentarios));
        $this->assertSame('Admin', $comentarios[0]->autor);
        $this->assertSame('admin', $comentarios[0]->perfil);
        $this->assertSame(9, $comentarios[0]->nps);
        $this->assertSame('2026-11-16 09:00:00', $comentarios[0]->criadoEm->format('Y-m-d H:i:s'));
    }

    public function testCommentsAreLimitedToTheMostRecentFifty(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->repo->create(
                $this->ana,
                'contador',
                $this->resposta(3, 5, "comentario {$i}"),
                $this->at('2026-11-16 09:00:00')->modify("+{$i} minutes")
            );
        }

        $resumo = $this->repo->resumo();

        $this->assertCount(50, $resumo->comentarios);
        $this->assertSame('comentario 55', $resumo->comentarios[0]->comentario);
        $this->assertSame('comentario 6', $resumo->comentarios[49]->comentario);
        $this->assertSame(55, $resumo->geral->total);
    }
}
