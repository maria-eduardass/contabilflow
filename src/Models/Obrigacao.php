<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

use DateTimeImmutable;

final class Obrigacao
{
    public const TIPOS = ['DAS', 'DARF', 'INSS', 'FGTS', 'ISS', 'ICMS', 'DCTFWEB', 'OUTRA'];

    /**
     * @param string|null $valor decimal como texto ("1234.50"), nunca float, para não perder centavos
     */
    public function __construct(
        public readonly int $id,
        public readonly int $empresaId,
        public readonly string $empresaNome,
        public readonly string $tipo,
        public readonly ?string $descricao,
        public readonly DateTimeImmutable $competencia,
        public readonly DateTimeImmutable $vencimento,
        public readonly ?string $valor,
        public readonly ObrigacaoStatus $status,
        public readonly ?DateTimeImmutable $enviadaEm,
        public readonly ?DateTimeImmutable $pagaEm,
        public readonly ?string $observacoes,
        public readonly int $criadoPor,
        public readonly int $versao,
    ) {
    }
}
