<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Core\AuthMiddleware;
use ContabilFlow\Core\ErrorHandler;
use ContabilFlow\Core\Kernel;
use ContabilFlow\Core\Request;
use ContabilFlow\Core\Response;
use ContabilFlow\Core\RoleMiddleware;
use ContabilFlow\Core\Router;
use ContabilFlow\Core\Session;
use ContabilFlow\Tests\Support\ArrayLogger;
use PHPUnit\Framework\TestCase;

/** Auth + Role encadeados pelo Kernel: anônimo vai ao login, perfil errado recebe 403, perfil certo chega à rota. */
final class AuthorizationPipelineTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $data = [];

    private function kernel(): Kernel
    {
        $router = new Router();
        $router->get('/empresas', static fn(): Response => Response::html('empresas'));
        $router->get('/usuarios', static fn(): Response => Response::html('usuarios'));
        $session = new Session($this->data);

        return new Kernel($router, new ErrorHandler(new ArrayLogger(), false), [
            new AuthMiddleware($session, ['/login']),
            new RoleMiddleware($session, ['/usuarios' => ['admin']]),
        ]);
    }

    public function testAnonymousIsRedirectedBeforeRoleCheck(): void
    {
        $this->assertSame(302, $this->kernel()->handle(Request::create('GET', '/usuarios'))->status);
    }

    public function testContadorReachesCompaniesButNotUsers(): void
    {
        $this->data = ['user_id' => 2, 'perfil' => 'contador'];

        $this->assertSame('empresas', $this->kernel()->handle(Request::create('GET', '/empresas'))->body);
        $this->assertSame(403, $this->kernel()->handle(Request::create('GET', '/usuarios'))->status);
    }

    public function testAdminReachesUsers(): void
    {
        $this->data = ['user_id' => 1, 'perfil' => 'admin'];

        $this->assertSame('usuarios', $this->kernel()->handle(Request::create('GET', '/usuarios'))->body);
    }
}
