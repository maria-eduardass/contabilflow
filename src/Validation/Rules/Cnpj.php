<?php

declare(strict_types=1);

namespace ContabilFlow\Validation\Rules;

/**
 * Validação e normalização de CNPJ.
 *
 * Por quê: só aceita dígitos e a pontuação usual (. / -). Qualquer outro
 * caractere invalida, em vez de ser descartado em silêncio, para não gravar
 * um CNPJ "limpo" a partir de lixo digitado. O banco guarda só 14 dígitos.
 */
final class Cnpj
{
    private const WEIGHTS = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    /** @return string|null 14 dígitos, ou null se o CNPJ for inválido */
    public static function normalize(string $input): ?string
    {
        if (preg_match('#^[0-9./-]+$#', $input) !== 1) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $input);
        if ($digits === null || strlen($digits) !== 14 || count(array_unique(str_split($digits))) === 1) {
            return null;
        }

        $numbers = array_map('intval', str_split($digits));
        $first = self::checkDigit(array_slice($numbers, 0, 12), array_slice(self::WEIGHTS, 1));
        $second = self::checkDigit(array_slice($numbers, 0, 13), self::WEIGHTS);

        return $numbers[12] === $first && $numbers[13] === $second ? $digits : null;
    }

    /**
     * @param list<int> $numbers
     * @param list<int> $weights
     */
    private static function checkDigit(array $numbers, array $weights): int
    {
        $sum = 0;
        foreach ($numbers as $i => $n) {
            $sum += $n * $weights[$i];
        }
        $rest = $sum % 11;

        return $rest < 2 ? 0 : 11 - $rest;
    }
}
