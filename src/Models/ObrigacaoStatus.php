<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

/**
 * Status de uma guia e as transições permitidas.
 *
 * pendente → enviada → paga, pendente → paga, pendente|enviada → cancelada e
 * cancelada → pendente. Uma guia paga não muda mais de status.
 */
enum ObrigacaoStatus: string
{
    case Pendente = 'pendente';
    case Enviada = 'enviada';
    case Paga = 'paga';
    case Cancelada = 'cancelada';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pendente => [self::Enviada, self::Paga, self::Cancelada],
            self::Enviada => [self::Paga, self::Cancelada],
            self::Cancelada => [self::Pendente],
            self::Paga => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    /**
     * Status de origem que podem ir para $to. Por quê: o repositório usa esta
     * lista no WHERE do UPDATE, então a regra vale de forma atômica no banco.
     *
     * @return list<self>
     */
    public static function sourcesFor(self $to): array
    {
        return array_values(array_filter(self::cases(), static fn(self $from): bool => $from->canTransitionTo($to)));
    }

    /** Guia ainda em aberto (conta para o semáforo de prazos). */
    public function isActive(): bool
    {
        return $this === self::Pendente || $this === self::Enviada;
    }
}
