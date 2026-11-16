<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

/**
 * Médias das 5 perguntas e NPS de um grupo de avaliações.
 *
 * NPS = % de promotores (nota 9 e 10) menos % de detratores (0 a 6); os neutros
 * (7 e 8) só entram no total. Vai de -100 a 100. Guardamos as SOMAS (não só as
 * médias) para dois grupos poderem ser combinados com o peso certo.
 */
final class EstatisticasAvaliacao
{
    /** @var array<string, float> médias por pergunta (q1..q5), duas casas */
    public readonly array $medias;
    public readonly float $mediaGeral;

    /**
     * @param array<string, int> $somas soma das notas por pergunta (q1..q5)
     */
    private function __construct(
        public readonly int $total,
        private readonly array $somas,
        public readonly int $promotores,
        public readonly int $neutros,
        public readonly int $detratores,
    ) {
        $medias = [];
        foreach (Avaliacao::PERGUNTAS as $key => $_) {
            $medias[$key] = $total === 0 ? 0.0 : round($somas[$key] / $total, 2);
        }
        $this->medias = $medias;
        $this->mediaGeral = $total === 0 ? 0.0 : round(array_sum($somas) / ($total * count($somas)), 2);
    }

    /** @param array<string, int> $somas */
    public static function fromSums(int $total, array $somas, int $promotores, int $neutros, int $detratores): self
    {
        $normalizadas = [];
        foreach (Avaliacao::PERGUNTAS as $key => $_) {
            $normalizadas[$key] = (int) ($somas[$key] ?? 0);
        }

        return new self($total, $normalizadas, $promotores, $neutros, $detratores);
    }

    public static function vazio(): self
    {
        return self::fromSums(0, [], 0, 0, 0);
    }

    /** @param list<self> $grupos */
    public static function combine(array $grupos): self
    {
        $somas = array_fill_keys(array_keys(Avaliacao::PERGUNTAS), 0);
        $total = $promotores = $neutros = $detratores = 0;
        foreach ($grupos as $g) {
            $total += $g->total;
            $promotores += $g->promotores;
            $neutros += $g->neutros;
            $detratores += $g->detratores;
            foreach ($g->somas as $key => $soma) {
                $somas[$key] += $soma;
            }
        }

        return self::fromSums($total, $somas, $promotores, $neutros, $detratores);
    }

    /** @return int|null null quando ainda não há avaliações */
    public function nps(): ?int
    {
        return $this->total === 0
            ? null
            : (int) round(($this->promotores - $this->detratores) / $this->total * 100);
    }

    /** Faixas padrão do NPS. */
    public function zona(): string
    {
        $nps = $this->nps();

        return match (true) {
            $nps === null => 'Sem avaliações',
            $nps >= 75 => 'Zona de excelência',
            $nps >= 50 => 'Zona de qualidade',
            $nps >= 0 => 'Zona de aperfeiçoamento',
            default => 'Zona crítica',
        };
    }
}
