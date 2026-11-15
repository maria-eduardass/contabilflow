<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Support;

use ContabilFlow\Core\Database;
use ContabilFlow\Core\Env;
use PDO;

/**
 * Acesso ao banco de testes. Por quê: centraliza a regra de que testes só
 * usam DB_NAME_TEST e permite pular a integração quando não há .env.
 */
final class TestDatabase
{
    public static function connectOrNull(): ?PDO
    {
        $path = __DIR__ . '/../../.env';
        if (!is_file($path)) {
            return null;
        }

        $env = Env::fromFile($path, [...Database::REQUIRED_KEYS, 'DB_NAME_TEST']);

        return Database::connect($env, 'DB_NAME_TEST');
    }

    /** Apaga todas as tabelas do banco de testes. */
    public static function reset(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $pdo->query('SHOW TABLES');
        if ($tables !== false) {
            foreach ($tables->fetchAll(PDO::FETCH_COLUMN) as $table) {
                $pdo->exec('DROP TABLE `' . $table . '`');
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
