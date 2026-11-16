<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

use DateTimeImmutable;

/** Registro de um lembrete gerado para uma guia (histórico). */
final class Lembrete
{
    public function __construct(
        public readonly int $id,
        public readonly int $obrigacaoId,
        public readonly int $usuarioId,
        public readonly string $usuarioNome,
        public readonly LembreteCanal $canal,
        public readonly string $mensagem,
        public readonly DateTimeImmutable $criadoEm,
    ) {
    }
}
