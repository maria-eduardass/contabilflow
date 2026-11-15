<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Core\Request;
use ContabilFlow\Core\Response;
use ContabilFlow\Core\Session;
use ContabilFlow\Core\SessionMiddleware;
use PHPUnit\Framework\TestCase;

final class SessionMiddlewareTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $data = [];
    private int $now = 1000;

    private function middleware(): SessionMiddleware
    {
        return new SessionMiddleware(new Session($this->data), 1800, fn(): int => $this->now);
    }

    private function dispatch(SessionMiddleware $middleware): Response
    {
        return $middleware->handle(Request::create('GET', '/'), fn() => Response::html('ok'));
    }

    public function testCallsNextAndRecordsActivity(): void
    {
        $response = $this->dispatch($this->middleware());

        $this->assertSame('ok', $response->body);
        $this->assertSame(1000, $this->data['_last_activity']);
    }

    public function testActiveSessionKeepsData(): void
    {
        $middleware = $this->middleware();
        $this->dispatch($middleware);
        $this->data['user_id'] = 5;

        $this->now = 2000;
        $this->dispatch($middleware);

        $this->assertSame(5, $this->data['user_id']);
    }

    public function testIdleSessionIsClearedBeforeNext(): void
    {
        $middleware = $this->middleware();
        $this->dispatch($middleware);
        $this->data['user_id'] = 5;

        $this->now = 1000 + 1801;
        $this->dispatch($middleware);

        $this->assertArrayNotHasKey('user_id', $this->data);
    }
}
