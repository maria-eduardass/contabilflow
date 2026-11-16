<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Services\DashboardService;
use ContabilFlow\Services\SemaforoService;
use ContabilFlow\Tests\Support\DashboardScenario;
use ContabilFlow\Tests\Support\FixedClock;
use ContabilFlow\Tests\Support\InMemoryEmpresaRepository;
use ContabilFlow\Tests\Support\InMemoryObrigacaoRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DashboardServiceTest extends TestCase
{
    private InMemoryObrigacaoRepository $guias;
    private InMemoryEmpresaRepository $empresas;
    private int $empresa;

    protected function setUp(): void
    {
        $this->empresas = new InMemoryEmpresaRepository();
        $this->guias = new InMemoryObrigacaoRepository($this->empresas);
        $this->empresa = $this->empresas->seed('Padaria Alfa', '11222333000181');
    }

    private function service(string $now = DashboardScenario::HOJE): DashboardService
    {
        return new DashboardService(
            $this->guias,
            new SemaforoService(new FixedClock(new DateTimeImmutable($now)))
        );
    }

    public function testIndicatorsCountEachColorPaidThisMonthAndOpenValue(): void
    {
        DashboardScenario::load($this->guias, $this->empresa, 1);

        $i = $this->service()->indicadores();

        $this->assertSame(3, $i->vencidas);
        $this->assertSame(2, $i->ate2dias);
        $this->assertSame(2, $i->ate7dias);
        $this->assertSame(2, $i->noPrazo);
        $this->assertSame(2, $i->pagasMes);
        $this->assertSame('900.00', $i->valorPendente);
        $this->assertSame(9, $i->totalEmAberto());
    }

    public function testEmptyDatabaseYieldsZeros(): void
    {
        $i = $this->service()->indicadores();

        $this->assertSame([
            'vencidas' => 0, 'ate2dias' => 0, 'ate7dias' => 0, 'noPrazo' => 0, 'pagasMes' => 0,
            'valorPendente' => '0.00',
        ], $i->toArray());
        $this->assertSame(0, $i->totalEmAberto());
    }

    public function testPaidAndCancelledNeverCountAsOverdue(): void
    {
        DashboardScenario::load($this->guias, $this->empresa, 1);

        // as pagas e a cancelada vencem em 10/11 (passado), mas só as 3 em aberto são "vencidas"
        $this->assertSame(3, $this->service()->indicadores()->vencidas);
    }

    public function testNumbersFollowTheClockOfTheDay(): void
    {
        DashboardScenario::load($this->guias, $this->empresa, 1);

        // Em 20/11 as seis guias até o dia 19 já venceram; 23/11 e 24/11 caem em 3 a 7 dias; 31/12 está no prazo.
        $i = $this->service('2026-11-20 08:00:00')->indicadores();

        $this->assertSame(6, $i->vencidas);
        $this->assertSame(0, $i->ate2dias);
        $this->assertSame(2, $i->ate7dias);
        $this->assertSame(1, $i->noPrazo);
    }

    public function testPaidCountFollowsTheCurrentMonth(): void
    {
        DashboardScenario::load($this->guias, $this->empresa, 1);

        $this->assertSame(1, $this->service('2026-10-15 09:00:00')->indicadores()->pagasMes); // paga em 31/10
        $this->assertSame(1, $this->service('2026-12-15 09:00:00')->indicadores()->pagasMes); // paga em 01/12
    }

    public function testThisWeekListsOpenGuidesFromTodayToSevenDaysAheadSorted(): void
    {
        DashboardScenario::load($this->guias, $this->empresa, 1);

        $semana = $this->service()->vencendoNaSemana();

        $due = array_map(static fn($o): string => $o->vencimento->format('Y-m-d'), $semana->items);
        $this->assertSame(['2026-11-16', '2026-11-18', '2026-11-19', '2026-11-23'], $due);
        $this->assertSame(4, $semana->total);
    }

    public function testThisWeekExcludesOverdueAndPaid(): void
    {
        DashboardScenario::load($this->guias, $this->empresa, 1);

        $tipos = array_map(static fn($o): string => $o->tipo, $this->service()->vencendoNaSemana()->items);

        $this->assertSame(['ISS', 'FGTS', 'ICMS', 'DCTFWEB'], $tipos);
    }

    public function testThisWeekIsEmptyWhenNothingDueSoon(): void
    {
        $this->assertSame([], $this->service()->vencendoNaSemana()->items);
    }
}
