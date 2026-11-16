<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

use DateTimeImmutable;

/**
 * Critérios de busca de guias. O prefixo "-" em $sort inverte a ordem.
 *
 * $faixa filtra pela cor do semáforo; obtenha-a com SemaforoService::faixa(), que
 * usa o Clock e mantém o filtro igual à cor mostrada na tela.
 */
final class ObrigacaoFiltro
{
    public const PER_PAGE = 20;
    public const SORTS = ['vencimento', 'competencia', 'valor', 'tipo', 'status', 'criado_em'];

    public function __construct(
        public readonly ?string $q = null,
        public readonly ?int $empresaId = null,
        public readonly ?string $tipo = null,
        public readonly ?ObrigacaoStatus $status = null,
        public readonly ?FaixaVencimento $faixa = null,
        public readonly ?DateTimeImmutable $de = null,
        public readonly ?DateTimeImmutable $ate = null,
        public readonly int $page = 1,
        public readonly string $sort = 'vencimento',
    ) {
    }
}
