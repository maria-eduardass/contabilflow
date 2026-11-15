<?php

declare(strict_types=1);

namespace ContabilFlow\Core;

use PDO;

/**
 * Fábrica de conexões PDO.
 *
 * Por quê: EMULATE_PREPARES=false faz o MySQL preparar a query de verdade,
 * então o valor nunca é interpolado no SQL (defesa real contra SQL Injection).
 * ERRMODE_EXCEPTION evita falhas silenciosas.
 */
final class Database
{
    public const REQUIRED_KEYS = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];

    public const OPTIONS = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    public static function connect(Env $env, string $nameKey = 'DB_NAME'): PDO
    {
        return new PDO(
            self::dsn($env->get('DB_HOST'), (int) $env->get('DB_PORT'), $env->get($nameKey)),
            $env->get('DB_USER'),
            $env->get('DB_PASS'),
            self::OPTIONS
        );
    }

    public static function dsn(string $host, int $port, string $name): string
    {
        return "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    }
}
