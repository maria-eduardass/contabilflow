<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

use DateTimeImmutable;

/**
 * Campos editáveis de uma guia (já validados). Status e datas de envio e
 * pagamento NÃO entram aqui: só mudam por transição de status.
 */
final class ObrigacaoDados
{
    public function __construct(
        public readonly int $empresaId,
        public readonly string $tipo,
        public readonly ?string $descricao,
        public readonly DateTimeImmutable $competencia,
        public readonly DateTimeImmutable $vencimento,
        public readonly ?string $valor,
        public readonly ?string $observacoes,
    ) {
    }
}
