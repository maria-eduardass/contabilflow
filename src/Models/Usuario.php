<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

use DateTimeImmutable;

final class Usuario
{
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly string $email,
        public readonly string $senhaHash,
        public readonly string $perfil,
        public readonly bool $ativo,
        public readonly int $tentativasFalhas,
        public readonly ?DateTimeImmutable $bloqueadoAte,
    ) {
    }

    /** O bloqueio termina exatamente em bloqueadoAte (nesse instante já é possível logar). */
    public function isLockedAt(DateTimeImmutable $now): bool
    {
        return $this->bloqueadoAte !== null && $this->bloqueadoAte > $now;
    }
}
