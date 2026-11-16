<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

use DateTimeImmutable;

interface AvaliacaoRepositoryInterface
{
    /** @return int id da avaliação gravada */
    public function create(int $usuarioId, string $perfil, AvaliacaoDados $dados, DateTimeImmutable $agora): int;

    public function resumo(): ResumoAvaliacoes;
}
