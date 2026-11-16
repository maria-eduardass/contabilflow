<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Models\FaixaVencimento;
use ContabilFlow\Models\Obrigacao;
use ContabilFlow\Models\ObrigacaoStatus;
use ContabilFlow\Models\Semaforo;
use ContabilFlow\Services\SemaforoService;
use ContabilFlow\Tests\Support\FixedClock;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SemaforoServiceTest extends TestCase
{
    private const HOJE = '2026-11-16';

    private function service(string $now = self::HOJE . ' 14:30:00'): SemaforoService
    {
        return new SemaforoService(new FixedClock(new DateTimeImmutable($now)));
    }

    private function guia(string $vencimento, ObrigacaoStatus $status = ObrigacaoStatus::Pendente): Obrigacao
    {
        return new Obrigacao(
            1,
            1,
            'Empresa',
            'DAS',
            null,
            new DateTimeImmutable('2026-10-01'),
            new DateTimeImmutable($vencimento),
            null,
            $status,
            null,
            null,
            null,
            1,
            1
        );
    }

    private function dayOffset(int $days): string
    {
        return (new DateTimeImmutable(self::HOJE))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /** @return array<string, array{0: int, 1: Semaforo}> */
    public static function boundaries(): array
    {
        return [
            'venceu há 30 dias' => [-30, Semaforo::Preto],
            'venceu ontem (-1)' => [-1, Semaforo::Preto],
            'vence hoje (0)' => [0, Semaforo::Vermelho],
            'amanhã (1)' => [1, Semaforo::Vermelho],
            'em 2 dias' => [2, Semaforo::Vermelho],
            'em 3 dias' => [3, Semaforo::Amarelo],
            'em 7 dias' => [7, Semaforo::Amarelo],
            'em 8 dias' => [8, Semaforo::Verde],
            'em 90 dias' => [90, Semaforo::Verde],
        ];
    }

    #[DataProvider('boundaries')]
    public function testClassifiesByDaysUntilDue(int $days, Semaforo $expected): void
    {
        $this->assertSame($expected, $this->service()->classificar($this->guia($this->dayOffset($days))));
    }

    public function testSentEntriesAreStillTrackedByDeadline(): void
    {
        $guia = $this->guia($this->dayOffset(-1), ObrigacaoStatus::Enviada);

        $this->assertSame(Semaforo::Preto, $this->service()->classificar($guia));
    }

    public function testPaidAndCancelledAreGreyEvenWhenOverdue(): void
    {
        $service = $this->service();

        $this->assertSame(Semaforo::Cinza, $service->classificar($this->guia('2026-01-01', ObrigacaoStatus::Paga)));
        $cancelada = $this->guia('2026-01-01', ObrigacaoStatus::Cancelada);
        $this->assertSame(Semaforo::Cinza, $service->classificar($cancelada));
    }

    public function testTimeOfDayDoesNotChangeTheClassification(): void
    {
        $guia = $this->guia(self::HOJE);

        $this->assertSame(Semaforo::Vermelho, $this->service(self::HOJE . ' 00:00:00')->classificar($guia));
        $this->assertSame(Semaforo::Vermelho, $this->service(self::HOJE . ' 23:59:59')->classificar($guia));
    }

    public function testClassifiesAgainstTheClockOfTheDay(): void
    {
        $guia = $this->guia('2026-11-20');

        $this->assertSame(Semaforo::Amarelo, $this->service('2026-11-16 10:00:00')->classificar($guia));
        $this->assertSame(Semaforo::Vermelho, $this->service('2026-11-18 10:00:00')->classificar($guia));
        $this->assertSame(Semaforo::Preto, $this->service('2026-11-21 00:00:01')->classificar($guia));
    }

    public function testClassifiesAcrossMonthAndYearBoundaries(): void
    {
        $service = $this->service('2026-12-30 09:00:00');

        $this->assertSame(Semaforo::Vermelho, $service->classificar($this->guia('2027-01-01')));
        $this->assertSame(Semaforo::Amarelo, $service->classificar($this->guia('2027-01-06')));
        $this->assertSame(Semaforo::Verde, $service->classificar($this->guia('2027-01-07')));
    }

    public function testDaylightSavingDoesNotShiftTheDayCount(): void
    {
        // Em 2018 o horário de verão do Brasil terminou em 18/02: o dia tem 25 horas.
        $tz = new DateTimeZone('America/Sao_Paulo');
        $service = new SemaforoService(new FixedClock(new DateTimeImmutable('2018-02-17 23:30:00', $tz)));
        $guia = new Obrigacao(
            1,
            1,
            'E',
            'DAS',
            null,
            new DateTimeImmutable('2018-01-01', $tz),
            new DateTimeImmutable('2018-02-19', $tz),
            null,
            ObrigacaoStatus::Pendente,
            null,
            null,
            null,
            1,
            1
        );

        $this->assertSame(Semaforo::Vermelho, $service->classificar($guia)); // 2 dias
    }

    public function testTodayIsMidnightOfTheClockDay(): void
    {
        $this->assertSame('2026-11-16 00:00:00', $this->service()->hoje()->format('Y-m-d H:i:s'));
    }

    // ---------- faixas (cor -> intervalo de vencimento) ----------

    public function testRangeForEachColor(): void
    {
        $service = $this->service();
        $fmt = static fn(?DateTimeImmutable $d): ?string => $d?->format('Y-m-d');

        $preto = $service->faixa(Semaforo::Preto);
        $this->assertTrue($preto->emAberto);
        $this->assertNull($preto->de);
        $this->assertSame('2026-11-15', $fmt($preto->ate));

        $vermelho = $service->faixa(Semaforo::Vermelho);
        $this->assertSame(['2026-11-16', '2026-11-18'], [$fmt($vermelho->de), $fmt($vermelho->ate)]);

        $amarelo = $service->faixa(Semaforo::Amarelo);
        $this->assertSame(['2026-11-19', '2026-11-23'], [$fmt($amarelo->de), $fmt($amarelo->ate)]);

        $verde = $service->faixa(Semaforo::Verde);
        $this->assertTrue($verde->emAberto);
        $this->assertSame('2026-11-24', $fmt($verde->de));
        $this->assertNull($verde->ate);

        $cinza = $service->faixa(Semaforo::Cinza);
        $this->assertFalse($cinza->emAberto);
        $this->assertNull($cinza->de);
        $this->assertNull($cinza->ate);
    }

    /** O SQL (faixas) e o PHP (classificar) nunca podem discordar. */
    public function testRangesAgreeWithClassificationForEveryDay(): void
    {
        $service = $this->service();
        $ranges = [];
        foreach (Semaforo::cases() as $cor) {
            $ranges[$cor->value] = $service->faixa($cor);
        }

        for ($days = -20; $days <= 30; $days++) {
            $due = new DateTimeImmutable($this->dayOffset($days));
            $color = $service->classificar($this->guia($due->format('Y-m-d')));

            foreach ($ranges as $name => $range) {
                $this->assertSame(
                    $name === $color->value,
                    $this->inRange($range, $due),
                    "dia {$days} na faixa {$name}"
                );
            }
        }
    }

    private function inRange(FaixaVencimento $range, DateTimeImmutable $due): bool
    {
        if (!$range->emAberto) {
            return false; // cinza não tem prazo; casa por status, não por data
        }

        return ($range->de === null || $due >= $range->de) && ($range->ate === null || $due <= $range->ate);
    }

    // ---------- enum Semaforo ----------

    public function testEveryColorHasLabelAndBadge(): void
    {
        $labels = [];
        foreach (Semaforo::cases() as $cor) {
            $this->assertNotSame('', $cor->label());
            $this->assertStringStartsWith('text-bg-', $cor->badgeClass());
            $labels[] = $cor->label();
        }

        $this->assertSame($labels, array_unique($labels));
    }

    public function testColorsParseFromQueryValues(): void
    {
        $this->assertSame(Semaforo::Amarelo, Semaforo::tryFrom('amarelo'));
        $this->assertNull(Semaforo::tryFrom('roxo'));
        $this->assertSame(['preto', 'vermelho', 'amarelo', 'verde', 'cinza'], Semaforo::values());
    }
}
