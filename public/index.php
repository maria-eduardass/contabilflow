<?php

declare(strict_types=1);

use ContabilFlow\Controllers\AuthController;
use ContabilFlow\Controllers\EmpresaController;
use ContabilFlow\Core\AuthMiddleware;
use ContabilFlow\Core\Csrf;
use ContabilFlow\Core\CsrfMiddleware;
use ContabilFlow\Core\Database;
use ContabilFlow\Core\Env;
use ContabilFlow\Core\ErrorHandler;
use ContabilFlow\Core\FileLogger;
use ContabilFlow\Core\Kernel;
use ContabilFlow\Core\LazyPdo;
use ContabilFlow\Core\Request;
use ContabilFlow\Core\RoleMiddleware;
use ContabilFlow\Core\Router;
use ContabilFlow\Core\Session;
use ContabilFlow\Core\SessionMiddleware;
use ContabilFlow\Core\SystemClock;
use ContabilFlow\Core\View;
use ContabilFlow\Models\EmpresaRepository;
use ContabilFlow\Models\UsuarioRepository;
use ContabilFlow\Services\AuthService;
use ContabilFlow\Validation\Validator;

// Ponto de entrada único. Por quê: o document root aponta para public/, então
// src/, views/ e .env nunca ficam acessíveis pelo navegador.
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$envFile = $root . '/.env';
$env = is_file($envFile) ? Env::fromFile($envFile) : null;
date_default_timezone_set($env?->getOr('APP_TIMEZONE', 'America/Sao_Paulo') ?? 'America/Sao_Paulo');
$debug = $env?->getOr('APP_DEBUG', 'false') === 'true';

$session = Session::start(Session::isHttps($_SERVER));
$csrf = new Csrf($session);
$clock = new SystemClock();

// Conexão preguiçosa: só abre o banco nas rotas que realmente o usam.
$db = new LazyPdo(static fn() => Database::connect(Env::fromFile($envFile, Database::REQUIRED_KEYS)));

$users = new UsuarioRepository($db);
$view = new View($root . '/views');
$validator = new Validator();

$auth = new AuthController(
    new AuthService($users, $clock),
    $view,
    $session,
    $csrf,
    $validator,
    $clock
);

$empresas = new EmpresaController(new EmpresaRepository($db), $users, $view, $session, $csrf, $validator);

$router = new Router();
(require $root . '/config/routes.php')($router, $auth, $empresas);

// Ordem importa: a sessão expira antes de o CSRF olhar o token, e o login é
// exigido antes de checar o perfil. Caminhos restritos a admin ficam neste mapa.
$adminOnly = ['admin'];
$kernel = new Kernel(
    $router,
    new ErrorHandler(new FileLogger($root . '/storage/logs/app.log'), $debug),
    [
        new SessionMiddleware($session),
        new CsrfMiddleware($csrf),
        new AuthMiddleware($session, ['/login']),
        new RoleMiddleware($session, ['/usuarios' => $adminOnly, '/avaliacoes/resumo' => $adminOnly]),
    ]
);
$kernel->handle(Request::fromGlobals($_SERVER, $_POST))->send();
