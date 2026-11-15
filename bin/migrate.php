<?php

declare(strict_types=1);

use ContabilFlow\Core\Database;
use ContabilFlow\Core\Env;
use ContabilFlow\Core\Migrator;

require __DIR__ . '/../vendor/autoload.php';

$env = Env::fromFile(__DIR__ . '/../.env', Database::REQUIRED_KEYS);
$applied = (new Migrator(Database::connect($env), __DIR__ . '/../database/migrations'))->run();

echo $applied === []
    ? "Nada a aplicar.\n"
    : "Aplicadas:\n - " . implode("\n - ", $applied) . "\n";
