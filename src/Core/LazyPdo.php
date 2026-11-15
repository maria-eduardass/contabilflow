<?php

declare(strict_types=1);

namespace ContabilFlow\Core;

use Closure;
use PDO;

/**
 * Conexão PDO criada só no primeiro uso.
 *
 * Por quê: rotas que não tocam o banco (ex.: GET /) não devem abrir conexão
 * nem falhar se o banco estiver fora do ar.
 */
final class LazyPdo
{
    private ?PDO $pdo = null;

    /** @param Closure(): PDO $factory */
    public function __construct(private Closure $factory)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= ($this->factory)();
    }
}
