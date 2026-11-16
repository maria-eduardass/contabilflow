<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Integration;

use ContabilFlow\Core\LazyPdo;
use ContabilFlow\Core\Migrator;
use ContabilFlow\Models\ObrigacaoFiltro;
use ContabilFlow\Models\ObrigacaoRepository;
use ContabilFlow\Models\Semaforo;
use ContabilFlow\Services\DashboardService;
use ContabilFlow\Services\SemaforoService;
use ContabilFlow\Tests\Support\DashboardScenario;
use ContabilFlow\Tests\Support\FixedClock;
use ContabilFlow\Tests\Support\TestDatabase;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/** O mesmo cenário do DashboardServiceTest, agora contra o MySQL: os números têm de ser idênticos. */
final class DashboardServiceIntegrationTest extends TestCase
{
    private DashboardService $service;
    private ObrigacaoRepository $repo;
    private int $empresa;
    private int $usuario;

    protected function setUp(): void
    {
        $pdo = TestDatabase::connectOrNull();
        if ($pdo === null) {
            $this->markTestSkipped('Crie o .env a partir do .env.example para rodar a integração.');
        }
        TestDatabase::reset($pdo);
        (new Migrator($pdo, __DIR__ . '/../../database/migrations'))->run();

        $pdo->exec(
            "INSERT INTO usuarios (nome, email, senha_hash, perfil) VALUES ('Ana', 'a@x.com', 'h', 'contador')"
        );
        $this->usuario = (int) $pdo->lastInsertId();
        $pdo->exec(
            "INSERT INTO empresas (razao_social, cnpj, regime) VALUES ('Padaria Alfa', '11222333000181', 'simples')"
        );
        $this->empresa = (int) $pdo->lastInsertId();

        $this->repo = new ObrigacaoRepository(new LazyPdo(fn(): PDO => $pdo));
        $this->service = $this->serviceAt(DashboardScenario::HOJE);
    }

    private function serviceAt(string $now): DashboardService
    {
        return new DashboardService($this->repo, new SemaforoService(new FixedClock(new DateTimeImmutable($now))));
    }

    public function testIndicatorsMatchTheScenario(): void
    {
        DashboardScenario::load($this->repo, $this->empresa, $this->usuario);

        $i = $this->service->indicadores();

        $this->assertSame(
            [
                'vencidas' => 3, 'ate2dias' => 2, 'ate7dias' => 2, 'noPrazo' => 2, 'pagasMes' => 2,
                'valorPendente' => '900.00',
            ],
            $i->toArray()
        );
    }

    public function testEmptyTableYieldsZerosInsteadOfNulls(): void
    {
        $this->assertSame(
            [
                'vencidas' => 0, 'ate2dias' => 0, 'ate7dias' => 0, 'noPrazo' => 0, 'pagasMes' => 0,
                'valorPendente' => '0.00',
            ],
            $this->service->indicadores()->toArray()
        );
    }

    public function testNumbersFollowTheClockOfTheDay(): void
    {
        DashboardScenario::load($this->repo, $this->empresa, $this->usuario);

        $i = $this->serviceAt('2026-11-20 08:00:00')->indicadores();

        $this->assertSame([6, 0, 2, 1], [$i->vencidas, $i->ate2dias, $i->ate7dias, $i->noPrazo]);
    }

    public function testPaidCountUsesTheMonthBoundaries(): void
    {
        DashboardScenario::load($this->repo, $this->empresa, $this->usuario);

        $this->assertSame(1, $this->serviceAt('2026-10-15 09:00:00')->indicadores()->pagasMes);
        $this->assertSame(1, $this->serviceAt('2026-12-15 09:00:00')->indicadores()->pagasMes);
    }

    public function testNullValuesAreIgnoredInTheOpenTotal(): void
    {
        DashboardScenario::load($this->repo, $this->empresa, $this->usuario);
        $this->repo->create(new \ContabilFlow\Models\ObrigacaoDados(
            $this->empresa,
            'ISS',
            null,
            new DateTimeImmutable('2026-09-01'),
            new DateTimeImmutable('2026-11-30'),
            null,
            null
        ), $this->usuario);

        $i = $this->service->indicadores();

        $this->assertSame('900.00', $i->valorPendente);
        $this->assertSame(10, $i->totalEmAberto());
    }

    public function testThisWeekListMatchesTheScenarioAndIsSorted(): void
    {
        DashboardScenario::load($this->repo, $this->empresa, $this->usuario);

        $semana = $this->service->vencendoNaSemana();

        $due = array_map(static fn($o): string => $o->vencimento->format('Y-m-d'), $semana->items);
        $this->assertSame(['2026-11-16', '2026-11-18', '2026-11-19', '2026-11-23'], $due);
        $this->assertSame(4, $semana->total);
    }

    public function testIndicatorsAgreeWithTheFiltersOfTheGuideList(): void
    {
        DashboardScenario::load($this->repo, $this->empresa, $this->usuario);
        $semaforo = new SemaforoService(new FixedClock(new DateTimeImmutable(DashboardScenario::HOJE)));
        $i = $this->service->indicadores();

        $cards = [
            'preto' => $i->vencidas, 'vermelho' => $i->ate2dias, 'amarelo' => $i->ate7dias, 'verde' => $i->noPrazo,
        ];
        foreach ($cards as $cor => $total) {
            $faixa = $semaforo->faixa(Semaforo::from($cor));
            $filtro = new ObrigacaoFiltro(faixa: $faixa);
            $this->assertSame($total, $this->repo->search($filtro)->total, "card {$cor} x lista");
        }
    }
}
