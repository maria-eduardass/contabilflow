<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

/** Por onde o lembrete foi entregue ao empresário. */
enum LembreteCanal: string
{
    case Whatsapp = 'whatsapp';
    case Email = 'email';
    case Copiado = 'copiado';

    public function label(): string
    {
        return match ($this) {
            self::Whatsapp => 'WhatsApp',
            self::Email => 'E-mail',
            self::Copiado => 'Texto copiado',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn(self $c): string => $c->value, self::cases());
    }
}
