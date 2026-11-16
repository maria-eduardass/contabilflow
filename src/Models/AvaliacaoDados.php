<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

/** Respostas de uma avaliação (já validadas): cinco notas de 1 a 5, NPS de 0 a 10 e comentário opcional. */
final class AvaliacaoDados
{
    public function __construct(
        public readonly int $q1,
        public readonly int $q2,
        public readonly int $q3,
        public readonly int $q4,
        public readonly int $q5,
        public readonly int $nps,
        public readonly ?string $comentario,
    ) {
    }
}
