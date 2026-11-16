<?php

declare(strict_types=1);

use ContabilFlow\App;
use ContabilFlow\Core\Database;
use ContabilFlow\Core\Env;
use ContabilFlow\Core\FileLogger;
use ContabilFlow\Core\LazyPdo;
use ContabilFlow\Core\Request;
use ContabilFlow\Core\Session;
use ContabilFlow\Core\SystemClock;

// Ponto de entrada único. Por quê: o document root aponta para public/, então
// src/, views/ e .env nunca ficam acessíveis pelo navegador. A montagem de
// rotas, controllers e middlewares fica em ContabilFlow\App (também usada nos testes).
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$envFile = $root . '/.env';
$env = is_file($envFile) ? Env::fromFile($envFile) : null;
date_default_timezone_set($env?->getOr('APP_TIMEZONE', 'America/Sao_Paulo') ?? 'America/Sao_Paulo');
$debug = $env?->getOr('APP_DEBUG', 'false') === 'true';

$session = Session::start(Session::isHttps($_SERVER));

// Conexão preguiçosa: só abre o banco nas rotas que realmente o usam.
$db = new LazyPdo(static fn() => Database::connect(Env::fromFile($envFile, Database::REQUIRED_KEYS)));

$app = App::build(
    $root,
    $session,
    $db,
    new SystemClock(),
    new FileLogger($root . '/storage/logs/app.log'),
    $debug
);
$app->handle(Request::fromGlobals($_SERVER, $_POST))->send();
