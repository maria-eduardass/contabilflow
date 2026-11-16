<?php

declare(strict_types=1);

namespace ContabilFlow;

use ContabilFlow\Controllers\AuthController;
use ContabilFlow\Controllers\AvaliacaoController;
use ContabilFlow\Controllers\DashboardController;
use ContabilFlow\Controllers\EmpresaController;
use ContabilFlow\Controllers\LembreteController;
use ContabilFlow\Controllers\ObrigacaoController;
use ContabilFlow\Controllers\UsuarioController;
use ContabilFlow\Core\AuthMiddleware;
use ContabilFlow\Core\Clock;
use ContabilFlow\Core\Csrf;
use ContabilFlow\Core\CsrfMiddleware;
use ContabilFlow\Core\ErrorHandler;
use ContabilFlow\Core\Kernel;
use ContabilFlow\Core\LazyPdo;
use ContabilFlow\Core\LoggerInterface;
use ContabilFlow\Core\Request;
use ContabilFlow\Core\Response;
use ContabilFlow\Core\RoleMiddleware;
use ContabilFlow\Core\Router;
use ContabilFlow\Core\Session;
use ContabilFlow\Core\SessionMiddleware;
use ContabilFlow\Core\View;
use ContabilFlow\Models\AvaliacaoRepository;
use ContabilFlow\Models\EmpresaRepository;
use ContabilFlow\Models\LembreteRepository;
use ContabilFlow\Models\ObrigacaoRepository;
use ContabilFlow\Models\UsuarioRepository;
use ContabilFlow\Services\AuthService;
use ContabilFlow\Services\DashboardService;
use ContabilFlow\Services\LembreteService;
use ContabilFlow\Services\SemaforoService;
use ContabilFlow\Validation\Validator;

/**
 * Raiz de composição: monta repositórios, serviços, controllers, rotas e middlewares.
 *
 * Por quê: o public/index.php e os testes HTTP usam EXATAMENTE esta montagem,
 * então um teste de ponta a ponta exercita as mesmas rotas, middlewares e
 * permissões do site real. Sessão, banco, relógio e log entram por parâmetro.
 */
final class App
{
    /** Caminhos restritos a administradores (valem também para os subcaminhos). */
    public const ADMIN_ONLY = ['/usuarios', '/avaliacoes/resumo'];

    private function __construct(public readonly Kernel $kernel, public readonly Router $router)
    {
    }

    public static function build(
        string $root,
        Session $session,
        LazyPdo $db,
        Clock $clock,
        LoggerInterface $logger,
        bool $debug = false,
    ): self {
        $csrf = new Csrf($session);
        $view = new View($root . '/views');
        $validator = new Validator();

        $users = new UsuarioRepository($db);
        $empresaRepo = new EmpresaRepository($db);
        $obrigacaoRepo = new ObrigacaoRepository($db);
        $lembreteRepo = new LembreteRepository($db);
        $semaforo = new SemaforoService($clock);

        $router = new Router();
        (require $root . '/config/routes.php')(
            $router,
            new AuthController(new AuthService($users, $clock), $view, $session, $csrf, $validator, $clock),
            new EmpresaController(
                $empresaRepo,
                $users,
                $view,
                $session,
                $csrf,
                $validator,
                $obrigacaoRepo,
                $semaforo
            ),
            new ObrigacaoController(
                $obrigacaoRepo,
                $empresaRepo,
                $semaforo,
                $clock,
                $view,
                $session,
                $csrf,
                $validator,
                $lembreteRepo
            ),
            new DashboardController(new DashboardService($obrigacaoRepo, $semaforo), $semaforo, $view, $session, $csrf),
            new LembreteController(
                $obrigacaoRepo,
                $empresaRepo,
                $lembreteRepo,
                new LembreteService($semaforo),
                $clock,
                $session,
                $validator
            ),
            new UsuarioController($users, $view, $session, $csrf, $validator),
            new AvaliacaoController(new AvaliacaoRepository($db), $clock, $view, $session, $csrf, $validator)
        );

        // Ordem importa: a sessão expira antes de o CSRF olhar o token, e o login é
        // exigido antes de checar o perfil.
        $adminRules = array_fill_keys(self::ADMIN_ONLY, ['admin']);
        $kernel = new Kernel(
            $router,
            new ErrorHandler($logger, $debug),
            [
                new SessionMiddleware($session),
                new CsrfMiddleware($csrf),
                new AuthMiddleware($session, ['/login'], $users),
                new RoleMiddleware($session, $adminRules),
            ]
        );

        return new self($kernel, $router);
    }

    public function handle(Request $request): Response
    {
        return $this->kernel->handle($request);
    }
}
