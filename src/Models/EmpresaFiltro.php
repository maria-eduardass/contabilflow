<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

/** Critérios de busca. O prefixo "-" em $sort inverte a ordem (ex.: "-criado_em"). */
final class EmpresaFiltro
{
    public const PER_PAGE = 20;
    public const SORTS = ['razao_social', 'cnpj', 'regime', 'criado_em'];

    public function __construct(
        public readonly ?string $q = null,
        public readonly ?string $regime = null,
        public readonly ?bool $ativo = null,
        public readonly int $page = 1,
        public readonly string $sort = 'razao_social',
    ) {
    }
}
