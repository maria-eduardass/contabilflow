<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Support;

use ContabilFlow\Models\Lembrete;
use ContabilFlow\Models\LembreteCanal;
use ContabilFlow\Models\LembreteRepositoryInterface;
use DateTimeImmutable;

final class InMemoryLembreteRepository implements LembreteRepositoryInterface
{
    /** @var list<Lembrete> */
    public array $rows = [];

    public function create(
        int $obrigacaoId,
        int $usuarioId,
        LembreteCanal $canal,
        string $mensagem,
        DateTimeImmutable $agora,
    ): int {
        $id = count($this->rows) + 1;
        $this->rows[] = new Lembrete($id, $obrigacaoId, $usuarioId, 'Usuário ' . $usuarioId, $canal, $mensagem, $agora);

        return $id;
    }

    public function listByObrigacao(int $obrigacaoId, int $limit = 20): array
    {
        $items = array_values(array_filter(
            $this->rows,
            static fn(Lembrete $l): bool => $l->obrigacaoId === $obrigacaoId
        ));

        return array_slice(array_reverse($items), 0, $limit);
    }
}
