<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

use DateTimeImmutable;

final class ComentarioAvaliacao
{
    public function __construct(
        public readonly string $perfil,
        public readonly ?string $autor,
        public readonly int $nps,
        public readonly string $comentario,
        public readonly DateTimeImmutable $criadoEm,
    ) {
    }
}
