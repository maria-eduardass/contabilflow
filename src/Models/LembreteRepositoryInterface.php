<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

use DateTimeImmutable;

interface LembreteRepositoryInterface
{
    public function create(
        int $obrigacaoId,
        int $usuarioId,
        LembreteCanal $canal,
        string $mensagem,
        DateTimeImmutable $agora,
    ): int;

    /**
     * Histórico da guia, do mais recente para o mais antigo.
     *
     * @return list<Lembrete>
     */
    public function listByObrigacao(int $obrigacaoId, int $limit = 20): array;
}
