<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

/** Totais agregados das guias, para o dashboard. */
final class ResumoObrigacoes
{
    /**
     * @param array<string, int> $porCor guias em aberto por cor do semáforo (chave = valor do enum Semaforo)
     * @param int $pagasNoPeriodo guias pagas dentro do período pedido
     * @param string $valorEmAberto soma dos valores das guias em aberto, decimal como texto ("1234.50")
     */
    public function __construct(
        public readonly array $porCor,
        public readonly int $pagasNoPeriodo,
        public readonly string $valorEmAberto,
    ) {
    }
}
