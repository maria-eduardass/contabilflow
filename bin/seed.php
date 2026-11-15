<?php

declare(strict_types=1);

use ContabilFlow\Core\Database;
use ContabilFlow\Core\Env;
use ContabilFlow\Core\Seeder;

require __DIR__ . '/../vendor/autoload.php';

$env = Env::fromFile(
    __DIR__ . '/../.env',
    [...Database::REQUIRED_KEYS, 'SEED_ADMIN_PASSWORD', 'SEED_CONTADOR_PASSWORD']
);

// Por quê: dados de demonstração com senha conhecida nunca podem ir para produção.
if ($env->getOr('APP_ENV', 'production') === 'production') {
    fwrite(STDERR, "Seed bloqueado: APP_ENV=production.\n");
    exit(1);
}

(new Seeder(Database::connect($env)))->run(
    new DateTimeImmutable('today', new DateTimeZone($env->getOr('APP_TIMEZONE', 'America/Sao_Paulo'))),
    $env->get('SEED_ADMIN_PASSWORD'),
    $env->get('SEED_CONTADOR_PASSWORD')
);

echo "Seed aplicado.\n";
