<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Core\Request;
use ContabilFlow\Core\Response;
use ContabilFlow\Core\RoleMiddleware;
use ContabilFlow\Core\Session;
use PHPUnit\Framework\TestCase;

final class RoleMiddlewareTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $data = [];
    private int $nextCalls = 0;

    private function dispatch(Request $request): Response
    {
        $middleware = new RoleMiddleware(new Session($this->data), [
            '/usuarios' => ['admin'],
            '/avaliacoes/resumo' => ['admin'],
        ]);

        return $middleware->handle($request, function (): Response {
            $this->nextCalls++;

            return Response::html('passou');
        });
    }

    public function testAdminCanAccessRestrictedPath(): void
    {
        $this->data['perfil'] = 'admin';

        $this->assertSame(200, $this->dispatch(Request::create('GET', '/usuarios'))->status);
    }

    public function testContadorGets403OnAdminPath(): void
    {
        $this->data['perfil'] = 'contador';

        $response = $this->dispatch(Request::create('GET', '/usuarios'));

        $this->assertSame(403, $response->status);
        $this->assertSame(0, $this->nextCalls);
    }

    public function testSubPathsAreAlsoRestricted(): void
    {
        $this->data['perfil'] = 'contador';

        $this->assertSame(403, $this->dispatch(Request::create('PATCH', '/usuarios/3/ativo'))->status);
        $this->assertSame(403, $this->dispatch(Request::create('GET', '/avaliacoes/resumo'))->status);
    }

    public function testSimilarPrefixIsNotRestricted(): void
    {
        $this->data['perfil'] = 'contador';

        $this->assertSame(200, $this->dispatch(Request::create('GET', '/usuarios-x'))->status);
    }

    public function testUnrestrictedPathPassesForAnyProfile(): void
    {
        $this->data['perfil'] = 'contador';

        $this->assertSame(200, $this->dispatch(Request::create('GET', '/empresas'))->status);
    }

    public function testMissingOrUnknownProfileIsForbiddenOnRestrictedPath(): void
    {
        $this->assertSame(403, $this->dispatch(Request::create('GET', '/usuarios'))->status);

        $this->data['perfil'] = 'cliente';
        $this->assertSame(403, $this->dispatch(Request::create('GET', '/usuarios'))->status);
    }

    public function testJsonClientsGetJsonError(): void
    {
        $this->data['perfil'] = 'contador';

        $response = $this->dispatch(Request::create('GET', '/usuarios', [], ['Accept' => 'application/json']));

        $this->assertSame(403, $response->status);
        $this->assertSame('application/json; charset=UTF-8', $response->headers['Content-Type']);
        $this->assertStringContainsString('FORBIDDEN', $response->body);
    }

    public function testHtmlClientsGetHtmlError(): void
    {
        $this->data['perfil'] = 'contador';

        $response = $this->dispatch(Request::create('GET', '/usuarios'));

        $this->assertSame('text/html; charset=UTF-8', $response->headers['Content-Type']);
    }
}
