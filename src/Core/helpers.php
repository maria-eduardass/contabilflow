<?php

declare(strict_types=1);

/**
 * Escapa um valor para saída em HTML.
 *
 * Por quê: toda variável impressa em view passa por aqui (defesa contra XSS).
 * ENT_QUOTES protege atributos com aspas simples ou duplas; ENT_SUBSTITUTE
 * troca bytes UTF-8 inválidos em vez de devolver string vazia.
 */
function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 11222333000181 → 11.222.333/0001-81 (outros formatos voltam como vieram). */
function cnpj_mask(string $cnpj): string
{
    if (preg_match('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', $cnpj, $m) !== 1) {
        return $cnpj;
    }

    return "{$m[1]}.{$m[2]}.{$m[3]}/{$m[4]}-{$m[5]}";
}

/** 5511987654321 → (11) 98765-4321; fixo: (11) 3456-7890. Outros formatos voltam como vieram. */
function phone_mask(string $phone): string
{
    if (preg_match('/^55(\d{2})(\d{4,5})(\d{4})$/', $phone, $m) !== 1) {
        return $phone;
    }

    return "({$m[1]}) {$m[2]}-{$m[3]}";
}

/** 1234.5 → R$ 1.234,50; sem valor, um traço. */
function money_br(?string $value): string
{
    return $value === null ? '—' : 'R$ ' . number_format((float) $value, 2, ',', '.');
}

/** Data no formato brasileiro (d/m/Y por padrão); sem data, um traço. */
function date_br(?DateTimeInterface $date, string $format = 'd/m/Y'): string
{
    return $date === null ? '—' : $date->format($format);
}
