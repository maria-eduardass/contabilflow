<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Core\AuthMiddleware;
use ContabilFlow\Core\Request;
use ContabilFlow\Core\Response;
use ContabilFlow\Core\Session;
use PHPUnit\Framework\TestCase;

final class AuthMiddlewareTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $data = [];
    private int $nextCalls = 0;

    private function dispatch(Request $request): Response
    {
        $middleware = new AuthMiddleware(new Session($this->data), ['/login']);

        return $middleware->handle($request, function (): Response {
            $this->nextCalls++;

            return Response::html('passou');
        });
    }

    public function testAnonymousHtmlRequestRedirectsToLogin(): void
    {
        $response = $this->dispatch(Request::create('GET', '/empresas'));

        $this->assertSame(302, $response->status);
        $this->assertSame('/login', $response->headers['Location']);
        $this->assertSame(0, $this->nextCalls);
    }

    public function testAnonymousJsonRequestGets401Json(): void
    {
        $response = $this->dispatch(Request::create('GET', '/api/x', [], ['Accept' => 'application/json']));

        $this->assertSame(401, $response->status);
        $this->assertStringContainsString('UNAUTHENTICATED', $response->body);
        $this->assertSame(0, $this->nextCalls);
    }

    public function testAnonymousNonGetRequestAlsoRedirects(): void
    {
        $response = $this->dispatch(Request::create('POST', '/empresas'));

        $this->assertSame(302, $response->status);
        $this->assertSame(0, $this->nextCalls);
    }

    public function testLoggedUserPasses(): void
    {
        $this->data['user_id'] = 7;

        $response = $this->dispatch(Request::create('GET', '/empresas'));

        $this->assertSame('passou', $response->body);
        $this->assertSame(1, $this->nextCalls);
    }

    public function testPublicPathsPassWithoutLogin(): void
    {
        $this->assertSame(200, $this->dispatch(Request::create('GET', '/login'))->status);
        $this->assertSame(200, $this->dispatch(Request::create('POST', '/login'))->status);
        $this->assertSame(2, $this->nextCalls);
    }

    public function testPublicPathIsExactNotPrefix(): void
    {
        $this->assertSame(302, $this->dispatch(Request::create('GET', '/login/../empresas'))->status);
        $this->assertSame(302, $this->dispatch(Request::create('GET', '/loginx'))->status);
    }

    public function testNonIntegerUserIdIsNotAuthenticated(): void
    {
        $this->data['user_id'] = 'abc';

        $this->assertSame(302, $this->dispatch(Request::create('GET', '/'))->status);
    }
}
