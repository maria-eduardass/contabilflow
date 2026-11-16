<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Models\ObrigacaoStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ObrigacaoStatusTest extends TestCase
{
    /** @return array<string, array{0: ObrigacaoStatus, 1: ObrigacaoStatus, 2: bool}> */
    public static function transitions(): array
    {
        $p = ObrigacaoStatus::Pendente;
        $e = ObrigacaoStatus::Enviada;
        $g = ObrigacaoStatus::Paga;
        $c = ObrigacaoStatus::Cancelada;

        return [
            'pendente -> enviada' => [$p, $e, true],
            'pendente -> paga' => [$p, $g, true],
            'pendente -> cancelada' => [$p, $c, true],
            'enviada -> paga' => [$e, $g, true],
            'enviada -> cancelada' => [$e, $c, true],
            'cancelada -> pendente' => [$c, $p, true],
            'enviada -> pendente' => [$e, $p, false],
            'pendente -> pendente' => [$p, $p, false],
            'enviada -> enviada' => [$e, $e, false],
            'cancelada -> enviada' => [$c, $e, false],
            'cancelada -> paga' => [$c, $g, false],
            'paga -> pendente' => [$g, $p, false],
            'paga -> enviada' => [$g, $e, false],
            'paga -> cancelada' => [$g, $c, false],
            'paga -> paga' => [$g, $g, false],
        ];
    }

    #[DataProvider('transitions')]
    public function testTransitionRules(ObrigacaoStatus $from, ObrigacaoStatus $to, bool $allowed): void
    {
        $this->assertSame($allowed, $from->canTransitionTo($to));
    }

    public function testSourcesForListsEveryStatusThatMayMoveToTarget(): void
    {
        $this->assertEquals([ObrigacaoStatus::Pendente], ObrigacaoStatus::sourcesFor(ObrigacaoStatus::Enviada));
        $this->assertEquals(
            [ObrigacaoStatus::Pendente, ObrigacaoStatus::Enviada],
            ObrigacaoStatus::sourcesFor(ObrigacaoStatus::Paga)
        );
        $this->assertEquals([ObrigacaoStatus::Cancelada], ObrigacaoStatus::sourcesFor(ObrigacaoStatus::Pendente));
    }

    public function testPaidIsFinal(): void
    {
        $this->assertSame([], ObrigacaoStatus::Paga->allowedNext());
    }

    public function testOnlyPendingAndSentAreActive(): void
    {
        $this->assertTrue(ObrigacaoStatus::Pendente->isActive());
        $this->assertTrue(ObrigacaoStatus::Enviada->isActive());
        $this->assertFalse(ObrigacaoStatus::Paga->isActive());
        $this->assertFalse(ObrigacaoStatus::Cancelada->isActive());
    }
}
