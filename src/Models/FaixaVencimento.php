<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

use DateTimeImmutable;

/**
 * Uma cor do semáforo expressa como filtro de banco: guias em aberto com
 * vencimento entre $de e $ate (inclusive; null = sem limite) ou, quando
 * $emAberto é false, guias já encerradas (pagas ou canceladas).
 */
final class FaixaVencimento
{
    public function __construct(
        public readonly bool $emAberto,
        public readonly ?DateTimeImmutable $de = null,
        public readonly ?DateTimeImmutable $ate = null,
    ) {
    }
}
