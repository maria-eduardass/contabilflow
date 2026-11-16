<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Models\EstatisticasAvaliacao;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EstatisticasAvaliacaoTest extends TestCase
{
    /** @param array<string, int> $somas */
    private function stats(int $total, array $somas, int $promotores, int $neutros, int $detratores): EstatisticasAvaliacao
    {
        return EstatisticasAvaliacao::fromSums($total, $somas, $promotores, $neutros, $detratores);
    }

    private const NO_SUMS = ['q1' => 0, 'q2' => 0, 'q3' => 0, 'q4' => 0, 'q5' => 0];

    public function testAveragesAreSumsDividedByTotalRoundedToTwoDecimals(): void
    {
        $stats = $this->stats(3, ['q1' => 13, 'q2' => 15, 'q3' => 9, 'q4' => 10, 'q5' => 12], 1, 1, 1);

        $this->assertSame(4.33, $stats->medias['q1']);
        $this->assertSame(5.0, $stats->medias['q2']);
        $this->assertSame(3.0, $stats->medias['q3']);
        $this->assertSame(3.33, $stats->medias['q4']);
        $this->assertSame(4.0, $stats->medias['q5']);
    }

    public function testOverallAverageIsTheMeanOfAllFiveQuestions(): void
    {
        $stats = $this->stats(2, ['q1' => 10, 'q2' => 8, 'q3' => 6, 'q4' => 6, 'q5' => 10], 2, 0, 0);

        $this->assertSame(4.0, $stats->mediaGeral); // (10+8+6+6+10) / (2*5)
    }

    /** @return array<string, array{0: int, 1: int, 2: int, 3: ?int}> promotores, neutros, detratores, nps */
    public static function npsCases(): array
    {
        return [
            'todos promotores' => [10, 0, 0, 100],
            'todos detratores' => [0, 0, 10, -100],
            'todos neutros' => [0, 10, 0, 0],
            'metade e metade' => [5, 0, 5, 0],
            'maioria promotora' => [6, 2, 2, 40],
            'um de três' => [1, 1, 1, 0],
            'arredonda para cima' => [2, 0, 1, 33],
            'arredonda para baixo' => [1, 0, 2, -33],
            'um só promotor' => [1, 0, 0, 100],
        ];
    }

    #[DataProvider('npsCases')]
    public function testNpsIsPromotersMinusDetractorsOverTotalInPercent(int $p, int $n, int $d, int $expected): void
    {
        $stats = $this->stats($p + $n + $d, self::NO_SUMS, $p, $n, $d);

        $this->assertSame($expected, $stats->nps());
    }

    public function testEmptyStatsHaveNoNpsAndZeroAverages(): void
    {
        $stats = EstatisticasAvaliacao::vazio();

        $this->assertSame(0, $stats->total);
        $this->assertNull($stats->nps());
        $this->assertSame(0.0, $stats->mediaGeral);
        $this->assertSame(['q1' => 0.0, 'q2' => 0.0, 'q3' => 0.0, 'q4' => 0.0, 'q5' => 0.0], $stats->medias);
        $this->assertSame('Sem avaliações', $stats->zona());
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function zones(): array
    {
        return [
            'excelência' => [100, 'Zona de excelência'],
            'limite excelência' => [75, 'Zona de excelência'],
            'qualidade' => [74, 'Zona de qualidade'],
            'limite qualidade' => [50, 'Zona de qualidade'],
            'aperfeiçoamento' => [49, 'Zona de aperfeiçoamento'],
            'zero' => [0, 'Zona de aperfeiçoamento'],
            'crítica' => [-1, 'Zona crítica'],
            'pior' => [-100, 'Zona crítica'],
        ];
    }

    #[DataProvider('zones')]
    public function testZoneFollowsTheStandardNpsBands(int $nps, string $zone): void
    {
        // Constrói um total de 100 respostas que produza exatamente esse NPS.
        $promotores = $nps >= 0 ? $nps : 0;
        $detratores = $nps < 0 ? -$nps : 0;
        $stats = $this->stats(100, self::NO_SUMS, $promotores, 100 - $promotores - $detratores, $detratores);

        $this->assertSame($nps, $stats->nps());
        $this->assertSame($zone, $stats->zona());
    }

    public function testCombineAddsGroupsTogetherForTheOverallNumbers(): void
    {
        $contadores = $this->stats(2, ['q1' => 8, 'q2' => 8, 'q3' => 8, 'q4' => 8, 'q5' => 8], 2, 0, 0);
        $admins = $this->stats(2, ['q1' => 4, 'q2' => 4, 'q3' => 4, 'q4' => 4, 'q5' => 4], 0, 0, 2);

        $geral = EstatisticasAvaliacao::combine([$contadores, $admins]);

        $this->assertSame(4, $geral->total);
        $this->assertSame(3.0, $geral->medias['q1']);
        $this->assertSame(0, $geral->nps()); // 2 promotores e 2 detratores
    }

    public function testCombineOfNothingIsEmpty(): void
    {
        $this->assertSame(0, EstatisticasAvaliacao::combine([])->total);
        $this->assertNull(EstatisticasAvaliacao::combine([])->nps());
    }
}
