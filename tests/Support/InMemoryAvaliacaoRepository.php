<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Support;

use ContabilFlow\Models\AvaliacaoDados;
use ContabilFlow\Models\AvaliacaoRepositoryInterface;
use ContabilFlow\Models\ComentarioAvaliacao;
use ContabilFlow\Models\EstatisticasAvaliacao;
use ContabilFlow\Models\ResumoAvaliacoes;
use DateTimeImmutable;

final class InMemoryAvaliacaoRepository implements AvaliacaoRepositoryInterface
{
    /** @var list<array{usuario: int, perfil: string, dados: AvaliacaoDados, quando: DateTimeImmutable}> */
    public array $rows = [];

    public function create(int $usuarioId, string $perfil, AvaliacaoDados $dados, DateTimeImmutable $agora): int
    {
        $this->rows[] = ['usuario' => $usuarioId, 'perfil' => $perfil, 'dados' => $dados, 'quando' => $agora];

        return count($this->rows);
    }

    public function resumo(): ResumoAvaliacoes
    {
        $perfis = array_unique(array_column($this->rows, 'perfil'));
        sort($perfis);

        $porPerfil = [];
        foreach ($perfis as $perfil) {
            $porPerfil[$perfil] = $this->estatisticas(array_values(array_filter(
                $this->rows,
                static fn(array $r): bool => $r['perfil'] === $perfil
            )));
        }

        $comentarios = [];
        foreach ($this->rows as $row) {
            $texto = $row['dados']->comentario;
            if ($texto !== null && $texto !== '') {
                $comentarios[] = new ComentarioAvaliacao(
                    $row['perfil'],
                    'Usuário ' . $row['usuario'],
                    $row['dados']->nps,
                    $texto,
                    $row['quando']
                );
            }
        }

        return new ResumoAvaliacoes(
            EstatisticasAvaliacao::combine(array_values($porPerfil)),
            $porPerfil,
            array_reverse($comentarios)
        );
    }

    /** @param list<array{usuario: int, perfil: string, dados: AvaliacaoDados, quando: DateTimeImmutable}> $rows */
    private function estatisticas(array $rows): EstatisticasAvaliacao
    {
        $somas = ['q1' => 0, 'q2' => 0, 'q3' => 0, 'q4' => 0, 'q5' => 0];
        $promotores = $neutros = $detratores = 0;
        foreach ($rows as $row) {
            $d = $row['dados'];
            $somas['q1'] += $d->q1;
            $somas['q2'] += $d->q2;
            $somas['q3'] += $d->q3;
            $somas['q4'] += $d->q4;
            $somas['q5'] += $d->q5;
            if ($d->nps >= 9) {
                $promotores++;
            } elseif ($d->nps >= 7) {
                $neutros++;
            } else {
                $detratores++;
            }
        }

        return EstatisticasAvaliacao::fromSums(count($rows), $somas, $promotores, $neutros, $detratores);
    }
}
