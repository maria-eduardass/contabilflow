<?php

declare(strict_types=1);

namespace ContabilFlow\Core;

final class Sql
{
    /**
     * Escapa os curingas de LIKE para o texto digitado valer como literal.
     *
     * Por quê: o caractere de escape é "!" (usado com ESCAPE '!' na consulta),
     * não a barra invertida, que muda de significado conforme o sql_mode.
     */
    public static function escapeLike(string $text): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text);
    }
}
