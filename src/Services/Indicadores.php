<?php

declare(strict_types=1);

namespace ContabilFlow\Services;

/** Números do dashboard. Cores seguem o semáforo: vencidas = preto, ate2dias = vermelho, ate7dias = amarelo. */
final class Indicadores
{
    public function __construct(
        public readonly int $vencidas,
        public readonly int $ate2dias,
        public readonly int $ate7dias,
        public readonly int $noPrazo,
        public readonly int $pagasMes,
        public readonly string $valorPendente,
    ) {
    }

    /** Guias em aberto, de qualquer cor. */
    public function totalEmAberto(): int
    {
        return $this->vencidas + $this->ate2dias + $this->ate7dias + $this->noPrazo;
    }

    /** @return array{vencidas: int, ate2dias: int, ate7dias: int, noPrazo: int, pagasMes: int, valorPendente: string} */
    public function toArray(): array
    {
        return [
            'vencidas' => $this->vencidas,
            'ate2dias' => $this->ate2dias,
            'ate7dias' => $this->ate7dias,
            'noPrazo' => $this->noPrazo,
            'pagasMes' => $this->pagasMes,
            'valorPendente' => $this->valorPendente,
        ];
    }
}
