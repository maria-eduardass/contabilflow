<?php

declare(strict_types=1);

namespace ContabilFlow\Core;

use RuntimeException;

/**
 * Lê o arquivo .env e guarda os valores em memória.
 *
 * Por quê: não usa getenv()/putenv() para evitar estado global, o que deixa
 * os testes isolados. Falha cedo se uma chave obrigatória faltar, em vez de
 * quebrar mais tarde com um erro obscuro de conexão.
 */
final class Env
{
    /** @param array<string, string> $values */
    private function __construct(private array $values)
    {
    }

    /** @param list<string> $required */
    public static function fromFile(string $path, array $required = []): self
    {
        $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : false;
        if ($lines === false) {
            throw new RuntimeException("Arquivo .env não encontrado: {$path}");
        }

        $values = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = self::unquote(trim($value));
        }

        $missing = array_values(array_diff($required, array_keys($values)));
        if ($missing !== []) {
            throw new RuntimeException('Variáveis obrigatórias ausentes no .env: ' . implode(', ', $missing));
        }

        return new self($values);
    }

    public function get(string $key): string
    {
        return $this->values[$key] ?? throw new RuntimeException("Variável ausente no .env: {$key}");
    }

    public function getOr(string $key, string $default): string
    {
        return $this->values[$key] ?? $default;
    }

    private static function unquote(string $value): string
    {
        $len = strlen($value);
        if ($len >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[$len - 1] === $value[0]) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
