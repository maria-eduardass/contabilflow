<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Integration;

use ContabilFlow\Core\LazyPdo;
use ContabilFlow\Core\Migrator;
use ContabilFlow\Models\ConflictException;
use ContabilFlow\Models\DuplicateException;
use ContabilFlow\Models\InvalidStateException;
use ContabilFlow\Models\ObrigacaoDados;
use ContabilFlow\Models\ObrigacaoFiltro;
use ContabilFlow\Models\ObrigacaoRepository;
use ContabilFlow\Models\ObrigacaoStatus;
use ContabilFlow\Models\RecordNotFoundException;
use ContabilFlow\Models\Semaforo;
use ContabilFlow\Models\StaleVersionException;
use ContabilFlow\Services\SemaforoService;
use ContabilFlow\Tests\Support\FixedClock;
use ContabilFlow\Tests\Support\TestDatabase;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class ObrigacaoRepositoryTest extends TestCase
{
    private const HOJE = '2026-11-16';

    private PDO $pdo;
    private ObrigacaoRepository $repo;
    private int $empresa;
    private int $outraEmpresa;
    private int $usuario;
    private DateTimeImmutable $agora;

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
            "INSERT INTO usuarios (nome, email, senha_hash, perfil) VALUES ('Ana', 'a@x.com', 'h', 'contador')"
        );
        $this->usuario = (int) $this->pdo->lastInsertId();
        $this->empresa = $this->novaEmpresa('Padaria Alfa', '11222333000181');
        $this->outraEmpresa = $this->novaEmpresa('Oficina Beta', '11444777000161');

        $this->agora = new DateTimeImmutable('2026-11-16 09:30:00');
        $this->repo = new ObrigacaoRepository(new LazyPdo(fn(): PDO => $pdo));
    }

    private function novaEmpresa(string $razao, string $cnpj): int
    {
        $this->pdo->prepare("INSERT INTO empresas (razao_social, cnpj, regime) VALUES (?, ?, 'simples')")
            ->execute([$razao, $cnpj]);

        return (int) $this->pdo->lastInsertId();
    }

    private function dados(
        string $tipo = 'DAS',
        string $competencia = '2026-10-01',
        string $vencimento = '2026-11-20',
        ?int $empresa = null,
        ?string $valor = '150.50',
        ?string $descricao = null,
    ): ObrigacaoDados {
        return new ObrigacaoDados(
            $empresa ?? $this->empresa,
            $tipo,
            $descricao,
            new DateTimeImmutable($competencia),
            new DateTimeImmutable($vencimento),
            $valor,
            'obs'
        );
    }

    private function semaforo(): SemaforoService
    {
        return new SemaforoService(new FixedClock(new DateTimeImmutable(self::HOJE . ' 10:00:00')));
    }

    private function criar(?ObrigacaoDados $dados = null): int
    {
        return $this->repo->create($dados ?? $this->dados(), $this->usuario);
    }

    private function mudar(int $id, ObrigacaoStatus $para, int $versao): void
    {
        $this->repo->changeStatus($id, $versao, $para, $this->agora);
    }

    // ---------- criar / buscar ----------

    public function testCreateAndFindRoundTrip(): void
    {
        $id = $this->criar();

        $o = $this->repo->find($id);

        $this->assertNotNull($o);
        $this->assertSame($this->empresa, $o->empresaId);
        $this->assertSame('Padaria Alfa', $o->empresaNome);
        $this->assertSame('DAS', $o->tipo);
        $this->assertSame('2026-10-01', $o->competencia->format('Y-m-d'));
        $this->assertSame('2026-11-20', $o->vencimento->format('Y-m-d'));
        $this->assertSame('150.50', $o->valor);
        $this->assertSame(ObrigacaoStatus::Pendente, $o->status);
        $this->assertNull($o->enviadaEm);
        $this->assertNull($o->pagaEm);
        $this->assertSame('obs', $o->observacoes);
        $this->assertSame($this->usuario, $o->criadoPor);
        $this->assertSame(1, $o->versao);
    }

    public function testFindUnknownReturnsNull(): void
    {
        $this->assertNull($this->repo->find(999));
    }

    public function testCompetenciaIsNormalizedToFirstDayOfMonth(): void
    {
        $id = $this->criar($this->dados(competencia: '2026-10-17'));

        $this->assertSame('2026-10-01', $this->repo->find($id)?->competencia->format('Y-m-d'));
    }

    public function testNullValueIsKept(): void
    {
        $id = $this->criar($this->dados(valor: null));

        $this->assertNull($this->repo->find($id)?->valor);
    }

    // ---------- duplicidade (dedup_key) ----------

    public function testSameCompanyTypeAndMonthIsDuplicate(): void
    {
        $this->criar();

        $this->expectException(DuplicateException::class);
        $this->criar($this->dados(vencimento: '2026-11-25'));
    }

    public function testDifferentTypeMonthOrCompanyIsNotDuplicate(): void
    {
        $this->criar();

        $this->criar($this->dados(tipo: 'DARF'));
        $this->criar($this->dados(competencia: '2026-09-01'));
        $this->criar($this->dados(empresa: $this->outraEmpresa));

        $this->assertSame(4, $this->repo->search(new ObrigacaoFiltro())->total);
    }

    public function testOutraTypeAllowsRepeatedEntries(): void
    {
        $this->criar($this->dados(tipo: 'OUTRA', descricao: 'Alvará'));
        $this->criar($this->dados(tipo: 'OUTRA', descricao: 'Taxa de lixo'));

        $this->assertSame(2, $this->repo->search(new ObrigacaoFiltro(tipo: 'OUTRA'))->total);
    }

    public function testCancelledEntryDoesNotBlockANewOne(): void
    {
        $id = $this->criar();
        $this->mudar($id, ObrigacaoStatus::Cancelada, 1);

        $novo = $this->criar();

        $this->assertNotSame($id, $novo);
    }

    public function testReactivatingCancelledEntryThatNowCollidesIsDuplicate(): void
    {
        $id = $this->criar();
        $this->mudar($id, ObrigacaoStatus::Cancelada, 1);
        $this->criar();

        $this->expectException(DuplicateException::class);
        $this->mudar($id, ObrigacaoStatus::Pendente, 2);
    }

    // ---------- editar ----------

    public function testUpdateChangesFieldsAndBumpsVersion(): void
    {
        $id = $this->criar();

        $this->repo->update($id, 1, $this->dados(tipo: 'ISS', vencimento: '2026-12-01', valor: '99.00'));

        $o = $this->repo->find($id);
        $this->assertSame('ISS', $o?->tipo);
        $this->assertSame('2026-12-01', $o->vencimento->format('Y-m-d'));
        $this->assertSame('99.00', $o->valor);
        $this->assertSame(2, $o->versao);
        $this->assertSame($this->usuario, $o->criadoPor);
    }

    public function testUpdateWithStaleVersionFailsWithoutOverwriting(): void
    {
        $id = $this->criar();
        $this->repo->update($id, 1, $this->dados(valor: '10.00'));

        try {
            $this->repo->update($id, 1, $this->dados(valor: '20.00'));
            $this->fail('Deveria lançar StaleVersionException');
        } catch (StaleVersionException) {
            $this->assertSame('10.00', $this->repo->find($id)?->valor);
        }
    }

    public function testUpdateOfPaidEntryIsRejected(): void
    {
        $id = $this->criar();
        $this->mudar($id, ObrigacaoStatus::Paga, 1);

        $this->expectException(InvalidStateException::class);
        $this->repo->update($id, 2, $this->dados(valor: '1.00'));
    }

    public function testUpdateOfMissingEntryThrowsNotFound(): void
    {
        $this->expectException(RecordNotFoundException::class);
        $this->repo->update(999, 1, $this->dados());
    }

    public function testUpdateIntoDuplicateThrows(): void
    {
        $this->criar();
        $id = $this->criar($this->dados(tipo: 'DARF'));

        $this->expectException(DuplicateException::class);
        $this->repo->update($id, 1, $this->dados(tipo: 'DAS'));
    }

    // ---------- transições de status ----------

    public function testMarkingAsSentStoresTimestamp(): void
    {
        $id = $this->criar();

        $this->mudar($id, ObrigacaoStatus::Enviada, 1);

        $o = $this->repo->find($id);
        $this->assertSame(ObrigacaoStatus::Enviada, $o?->status);
        $this->assertSame('2026-11-16 09:30:00', $o->enviadaEm?->format('Y-m-d H:i:s'));
        $this->assertNull($o->pagaEm);
        $this->assertSame(2, $o->versao);
    }

    public function testMarkingAsPaidStoresTimestampAndKeepsSentDate(): void
    {
        $id = $this->criar();
        $this->mudar($id, ObrigacaoStatus::Enviada, 1);
        $later = new DateTimeImmutable('2026-11-17 08:00:00');

        $this->repo->changeStatus($id, 2, ObrigacaoStatus::Paga, $later);

        $o = $this->repo->find($id);
        $this->assertSame(ObrigacaoStatus::Paga, $o?->status);
        $this->assertSame('2026-11-17 08:00:00', $o->pagaEm?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-16 09:30:00', $o->enviadaEm?->format('Y-m-d H:i:s'));
    }

    public function testPendingCanGoStraightToPaid(): void
    {
        $id = $this->criar();

        $this->mudar($id, ObrigacaoStatus::Paga, 1);

        $this->assertSame(ObrigacaoStatus::Paga, $this->repo->find($id)?->status);
    }

    public function testReopeningCancelledEntryClearsSentDate(): void
    {
        $id = $this->criar();
        $this->mudar($id, ObrigacaoStatus::Enviada, 1);
        $this->mudar($id, ObrigacaoStatus::Cancelada, 2);

        $this->mudar($id, ObrigacaoStatus::Pendente, 3);

        $o = $this->repo->find($id);
        $this->assertSame(ObrigacaoStatus::Pendente, $o?->status);
        $this->assertNull($o->enviadaEm);
        $this->assertSame(4, $o->versao);
    }

    /** @return array<string, array{0: list<ObrigacaoStatus>, 1: ObrigacaoStatus}> */
    public static function invalidMoves(): array
    {
        $p = ObrigacaoStatus::Pendente;
        $e = ObrigacaoStatus::Enviada;
        $g = ObrigacaoStatus::Paga;
        $c = ObrigacaoStatus::Cancelada;

        return [
            'pendente -> pendente' => [[], $p],
            'enviada -> pendente' => [[$e], $p],
            'enviada -> enviada' => [[$e], $e],
            'cancelada -> enviada' => [[$c], $e],
            'cancelada -> paga' => [[$c], $g],
            'paga -> pendente' => [[$g], $p],
            'paga -> cancelada' => [[$g], $c],
            'paga -> paga' => [[$g], $g],
        ];
    }

    /** @param list<ObrigacaoStatus> $path */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidMoves')]
    public function testInvalidTransitionsAreRejectedAndChangeNothing(array $path, ObrigacaoStatus $target): void
    {
        $id = $this->criar();
        $versao = 1;
        foreach ($path as $step) {
            $this->mudar($id, $step, $versao++);
        }

        try {
            $this->mudar($id, $target, $versao);
            $this->fail('Deveria lançar InvalidStateException');
        } catch (InvalidStateException) {
            $this->assertSame($versao, $this->repo->find($id)?->versao);
        }
    }

    public function testStatusChangeWithStaleVersionIsAConflict(): void
    {
        $id = $this->criar();
        $this->mudar($id, ObrigacaoStatus::Paga, 1); // outra pessoa marcou como paga

        $this->expectException(StaleVersionException::class);
        $this->mudar($id, ObrigacaoStatus::Cancelada, 1);
    }

    public function testStatusChangeOfMissingEntryThrowsNotFound(): void
    {
        $this->expectException(RecordNotFoundException::class);
        $this->mudar(999, ObrigacaoStatus::Paga, 1);
    }

    // ---------- excluir ----------

    public function testDeleteRemovesEntry(): void
    {
        $id = $this->criar();

        $this->assertTrue($this->repo->delete($id));
        $this->assertNull($this->repo->find($id));
    }

    public function testDeleteOfMissingEntryReturnsFalse(): void
    {
        $this->assertFalse($this->repo->delete(999));
    }

    public function testDeleteOfPaidEntryIsAConflictAndKeepsRow(): void
    {
        $id = $this->criar();
        $this->mudar($id, ObrigacaoStatus::Paga, 1);

        try {
            $this->repo->delete($id);
            $this->fail('Deveria lançar ConflictException');
        } catch (ConflictException) {
            $this->assertNotNull($this->repo->find($id));
        }
    }

    // ---------- busca ----------

    public function testSearchFiltersByTypeStatusAndCompany(): void
    {
        $this->criar();
        $paga = $this->criar($this->dados(tipo: 'DARF'));
        $this->mudar($paga, ObrigacaoStatus::Paga, 1);
        $this->criar($this->dados(empresa: $this->outraEmpresa));

        $this->assertSame(2, $this->repo->search(new ObrigacaoFiltro(tipo: 'DAS'))->total);
        $this->assertSame(1, $this->repo->search(new ObrigacaoFiltro(status: ObrigacaoStatus::Paga))->total);
        $this->assertSame(2, $this->repo->search(new ObrigacaoFiltro(empresaId: $this->empresa))->total);
        $outra = new ObrigacaoFiltro(empresaId: $this->outraEmpresa, tipo: 'DARF');
        $this->assertSame(0, $this->repo->search($outra)->total);
    }

    public function testSearchByTextMatchesDescriptionAndCompanyName(): void
    {
        $this->criar($this->dados(tipo: 'OUTRA', descricao: 'Alvará de funcionamento'));
        $this->criar($this->dados(empresa: $this->outraEmpresa));

        $this->assertSame(1, $this->repo->search(new ObrigacaoFiltro(q: 'alvará'))->total);
        $this->assertSame(1, $this->repo->search(new ObrigacaoFiltro(q: 'alfa'))->total);
        $this->assertSame(1, $this->repo->search(new ObrigacaoFiltro(q: 'beta'))->total);
        $this->assertSame(0, $this->repo->search(new ObrigacaoFiltro(q: "' OR '1'='1"))->total);
    }

    public function testSearchTreatsLikeWildcardsAsLiterals(): void
    {
        $this->criar($this->dados(tipo: 'OUTRA', descricao: 'Taxa 100%'));
        $this->criar($this->dados(tipo: 'OUTRA', competencia: '2026-09-01', descricao: 'Outra coisa'));

        $this->assertSame(1, $this->repo->search(new ObrigacaoFiltro(q: '100%'))->total);
        $this->assertSame(0, $this->repo->search(new ObrigacaoFiltro(q: '_'))->total);
    }

    public function testSearchByDueDatePeriodIsInclusive(): void
    {
        $this->criar($this->dados(tipo: 'DAS', vencimento: '2026-11-10'));
        $this->criar($this->dados(tipo: 'DARF', vencimento: '2026-11-20'));
        $this->criar($this->dados(tipo: 'ISS', vencimento: '2026-11-30'));

        $filtro = new ObrigacaoFiltro(
            de: new DateTimeImmutable('2026-11-20'),
            ate: new DateTimeImmutable('2026-11-30')
        );
        $this->assertSame(2, $this->repo->search($filtro)->total);
        $this->assertSame(1, $this->repo->search(new ObrigacaoFiltro(ate: new DateTimeImmutable('2026-11-10')))->total);
        $this->assertSame(1, $this->repo->search(new ObrigacaoFiltro(de: new DateTimeImmutable('2026-11-30')))->total);
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function colors(): array
    {
        return [
            'preto' => ['preto', ['2026-11-10']],
            'vermelho' => ['vermelho', ['2026-11-16', '2026-11-18']],
            'amarelo' => ['amarelo', ['2026-11-19', '2026-11-23']],
            'verde' => ['verde', ['2026-11-24', '2026-12-31']],
            'cinza' => ['cinza', ['2026-11-10', '2026-12-31']],
        ];
    }

    /** @param list<string> $expectedDueDates */
    #[\PHPUnit\Framework\Attributes\DataProvider('colors')]
    public function testSearchByTrafficLightColor(string $cor, array $expectedDueDates): void
    {
        $tipos = ['DAS', 'DARF', 'INSS', 'FGTS', 'ISS', 'ICMS', 'DCTFWEB', 'OUTRA'];
        $vencimentos = [
            '2026-11-10', '2026-11-16', '2026-11-18', '2026-11-19', '2026-11-23', '2026-11-24', '2026-12-31',
        ];
        foreach ($vencimentos as $i => $venc) {
            $this->criar($this->dados(tipo: $tipos[$i], vencimento: $venc));
        }
        // Paga (vencida) e cancelada: ficam cinza, nunca preto.
        $paga = $this->criar($this->dados(tipo: 'DAS', competencia: '2026-08-01', vencimento: '2026-11-10'));
        $this->mudar($paga, ObrigacaoStatus::Paga, 1);
        $cancelada = $this->criar($this->dados(tipo: 'DAS', competencia: '2026-07-01', vencimento: '2026-12-31'));
        $this->mudar($cancelada, ObrigacaoStatus::Cancelada, 1);

        $filtro = new ObrigacaoFiltro(faixa: $this->semaforo()->faixa(Semaforo::from($cor)), sort: 'vencimento');
        $result = $this->repo->search($filtro);

        $due = array_map(static fn($o): string => $o->vencimento->format('Y-m-d'), $result->items);
        $this->assertSame($expectedDueDates, $due, $cor);
    }

    public function testSentEntriesStillCountForTrafficLight(): void
    {
        $id = $this->criar($this->dados(vencimento: '2026-11-10'));
        $this->mudar($id, ObrigacaoStatus::Enviada, 1);

        $filtro = new ObrigacaoFiltro(faixa: $this->semaforo()->faixa(Semaforo::Preto));

        $this->assertSame(1, $this->repo->search($filtro)->total);
    }

    public function testPaginationAndTotals(): void
    {
        $tipos = ['DAS', 'DARF', 'INSS', 'FGTS', 'ISS', 'ICMS', 'DCTFWEB'];
        for ($m = 1; $m <= 4; $m++) {
            foreach ($tipos as $tipo) {
                $this->criar($this->dados(
                    tipo: $tipo,
                    competencia: sprintf('2026-%02d-01', $m),
                    vencimento: sprintf('2026-%02d-20', $m)
                ));
            }
        }

        $p1 = $this->repo->search(new ObrigacaoFiltro());
        $p2 = $this->repo->search(new ObrigacaoFiltro(page: 2));

        $this->assertSame(28, $p1->total);
        $this->assertCount(20, $p1->items);
        $this->assertCount(8, $p2->items);
        $this->assertSame(2, $p1->pages());
        $this->assertSame([], $this->repo->search(new ObrigacaoFiltro(page: 9))->items);
    }

    public function testSortIsWhitelistedAndSupportsDescending(): void
    {
        $this->criar($this->dados(tipo: 'DAS', vencimento: '2026-11-10'));
        $this->criar($this->dados(tipo: 'DARF', vencimento: '2026-11-30'));

        $asc = $this->repo->search(new ObrigacaoFiltro());
        $desc = $this->repo->search(new ObrigacaoFiltro(sort: '-vencimento'));

        $this->assertSame('2026-11-10', $asc->items[0]->vencimento->format('Y-m-d'));
        $this->assertSame('2026-11-30', $desc->items[0]->vencimento->format('Y-m-d'));
    }

    public function testInvalidSortIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->search(new ObrigacaoFiltro(sort: 'valor; DROP TABLE obrigacoes'));
    }
}
