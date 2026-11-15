<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

/** Campos graváveis de uma empresa (já validados e normalizados: CNPJ e WhatsApp só com dígitos). */
final class EmpresaDados
{
    public function __construct(
        public readonly string $razaoSocial,
        public readonly ?string $nomeFantasia,
        public readonly string $cnpj,
        public readonly string $regime,
        public readonly ?string $email,
        public readonly ?string $whatsapp,
        public readonly ?int $responsavelId,
        public readonly bool $ativo = true,
    ) {
    }
}
