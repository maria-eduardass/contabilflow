<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

/** Cor do semáforo de prazos de uma guia. */
enum Semaforo: string
{
    case Preto = 'preto';
    case Vermelho = 'vermelho';
    case Amarelo = 'amarelo';
    case Verde = 'verde';
    case Cinza = 'cinza';

    public function label(): string
    {
        return match ($this) {
            self::Preto => 'Vencida',
            self::Vermelho => 'Vence em até 2 dias',
            self::Amarelo => 'Vence em 3 a 7 dias',
            self::Verde => 'No prazo',
            self::Cinza => 'Paga ou cancelada',
        };
    }

    /** Classe do badge do Bootstrap. */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Preto => 'text-bg-dark',
            self::Vermelho => 'text-bg-danger',
            self::Amarelo => 'text-bg-warning',
            self::Verde => 'text-bg-success',
            self::Cinza => 'text-bg-secondary',
        };
    }

    /** @return list<string> valores aceitos em filtros de URL */
    public static function values(): array
    {
        return array_map(static fn(self $c): string => $c->value, self::cases());
    }
}
