<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

final class Empresa
{
    public const REGIME_LABELS = [
        'mei' => 'MEI',
        'simples' => 'Simples Nacional',
        'presumido' => 'Lucro Presumido',
        'real' => 'Lucro Real',
    ];
    public const REGIMES = ['mei', 'simples', 'presumido', 'real'];

    public function __construct(
        public readonly int $id,
        public readonly string $razaoSocial,
        public readonly ?string $nomeFantasia,
        public readonly string $cnpj,
        public readonly string $regime,
        public readonly ?string $email,
        public readonly ?string $whatsapp,
        public readonly ?int $responsavelId,
        public readonly bool $ativo,
        public readonly int $versao,
    ) {
    }
}
