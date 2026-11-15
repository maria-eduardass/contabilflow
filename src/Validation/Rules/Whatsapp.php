<?php

declare(strict_types=1);

namespace ContabilFlow\Validation\Rules;

/**
 * Normaliza telefone brasileiro para 55 + DDD + número (12 ou 13 dígitos).
 *
 * Por quê: é o formato que o link wa.me exige e o que o CHECK do banco aceita.
 * O código do país é decidido pelo TAMANHO, não pelo prefixo, porque DDD 55
 * (RS) existe: 11 dígitos = DDD + celular (ganha o 55); 13 = já traz o 55.
 */
final class Whatsapp
{
    public static function normalize(string $input): ?string
    {
        if (preg_match('/^\+?[0-9 ()-]+$/', $input) !== 1) {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', $input);
        $length = strlen($digits);

        if ($length === 10 || $length === 11) {
            $national = $digits;
        } elseif (($length === 12 || $length === 13) && str_starts_with($digits, '55')) {
            $national = substr($digits, 2);
        } else {
            return null;
        }

        // DDD 11-99 (sem zero); celular = 9 + 8 dígitos; fixo = 2 a 5 + 7 dígitos.
        if (preg_match('/^[1-9][1-9](9[0-9]{8}|[2-5][0-9]{7})$/', $national) !== 1) {
            return null;
        }

        return '55' . $national;
    }
}
