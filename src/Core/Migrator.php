<?php

declare(strict_types=1);

namespace ContabilFlow\Core;

use PDO;
use RuntimeException;

/**
 * Aplica os arquivos database/migrations/*.sql em ordem alfabética e
 * registra os já aplicados em schema_migrations.
 *
 * Por quê: cada arquivo contém UM comando SQL. O MySQL faz commit implícito
 * em DDL, então transação não ajudaria, e um comando por arquivo evita o
 * modo multi-statement do PDO (superfície extra para SQL Injection).
 */
final class Migrator
{
    public function __construct(private PDO $pdo, private string $directory)
    {
    }

    /** @return list<string> nomes dos arquivos aplicados nesta execução */
    public function run(): array
    {
        $files = glob($this->directory . '/*.sql');
        if (!is_dir($this->directory) || $files === false) {
            throw new RuntimeException("Pasta de migrações inválida: {$this->directory}");
        }
        sort($files);

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . 'nome VARCHAR(190) NOT NULL PRIMARY KEY, '
            . 'aplicada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $done = $this->pdo->query('SELECT nome FROM schema_migrations');
        $already = $done === false ? [] : $done->fetchAll(PDO::FETCH_COLUMN);

        $applied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $already, true)) {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException("Não foi possível ler a migração: {$name}");
            }
            $this->pdo->exec($sql);
            $this->pdo->prepare('INSERT INTO schema_migrations (nome) VALUES (?)')->execute([$name]);
            $applied[] = $name;
        }

        return $applied;
    }
}
