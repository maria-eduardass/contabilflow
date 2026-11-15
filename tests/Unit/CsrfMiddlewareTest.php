<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Core\Csrf;
use ContabilFlow\Core\CsrfMiddleware;
use ContabilFlow\Core\Request;
use ContabilFlow\Core\Response;
use ContabilFlow\Core\Session;
use PHPUnit\Framework\TestCase;

final class CsrfMiddlewareTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $data = [];
    private Csrf $csrf;
    private CsrfMiddleware $middleware;
    private int $nextCalls = 0;

    protected function setUp(): void
    {
        $this->csrf = new Csrf(new Session($this->data));
        $this->middleware = new CsrfMiddleware($this->csrf);
    }

    private function dispatch(Request $request): Response
    {
        return $this->middleware->handle($request, function (Request $r): Response {
            $this->nextCalls++;

            return Response::html('passou');
        });
    }

    public function testGetPassesWithoutToken(): void
    {
        $response = $this->dispatch(Request::create('GET', '/empresas'));

        $this->assertSame('passou', $response->body);
    }

    public function testPostWithoutTokenReturns419AndDoesNotCallNext(): void
    {
        $response = $this->dispatch(Request::create('POST', '/empresas'));

        $this->assertSame(419, $response->status);
        $this->assertSame(0, $this->nextCalls);
    }

    public function testPostWithWrongTokenReturns419(): void
    {
        $this->csrf->token();

        $this->assertSame(419, $this->dispatch(Request::create('POST', '/x', ['_csrf' => 'errado']))->status);
    }

    public function testPostWithValidTokenPasses(): void
    {
        $response = $this->dispatch(Request::create('POST', '/x', ['_csrf' => $this->csrf->token()]));

        $this->assertSame(200, $response->status);
        $this->assertSame(1, $this->nextCalls);
    }

    public function testMethodOverrideRequestsAreProtected(): void
    {
        foreach (['PUT', 'PATCH', 'DELETE'] as $verb) {
            $response = $this->dispatch(Request::create('POST', '/empresas/1', ['_method' => $verb]));

            $this->assertSame(419, $response->status, $verb);
        }
    }

    public function testHeaderTokenWorksForFetchRequests(): void
    {
        $request = Request::create('POST', '/api/x', [], ['X-CSRF-Token' => $this->csrf->token()]);

        $this->assertSame(200, $this->dispatch($request)->status);
    }

    public function testJsonClientsGetJsonError(): void
    {
        $response = $this->dispatch(Request::create('POST', '/api/x', [], ['Accept' => 'application/json']));

        $this->assertSame(419, $response->status);
        $this->assertSame('application/json; charset=UTF-8', $response->headers['Content-Type']);
        $this->assertStringContainsString('CSRF_INVALID', $response->body);
    }

    public function testHtmlClientsGetHtmlError(): void
    {
        $response = $this->dispatch(Request::create('POST', '/x'));

        $this->assertSame('text/html; charset=UTF-8', $response->headers['Content-Type']);
    }
}
