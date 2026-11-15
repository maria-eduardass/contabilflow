<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Core\ErrorHandler;
use ContabilFlow\Core\Kernel;
use ContabilFlow\Core\Request;
use ContabilFlow\Core\Response;
use ContabilFlow\Core\Router;
use ContabilFlow\Tests\Support\ArrayLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class KernelTest extends TestCase
{
    private Router $router;
    private ArrayLogger $logger;
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->logger = new ArrayLogger();
        $this->kernel = new Kernel($this->router, new ErrorHandler($this->logger, false));
    }

    public function testReturnsRouteResponse(): void
    {
        $this->router->get('/', fn() => Response::html('OK'));

        $response = $this->kernel->handle(Request::create('GET', '/'));

        $this->assertSame('OK', $response->body);
    }

    public function testUnknownRouteIs404WithoutLoggingError(): void
    {
        $response = $this->kernel->handle(Request::create('GET', '/nada'));

        $this->assertSame(404, $response->status);
        $this->assertSame([], $this->logger->messages);
    }

    public function testControllerExceptionBecomesGeneric500AndIsLogged(): void
    {
        $this->router->get('/boom', function (): Response {
            throw new RuntimeException('detalhe interno');
        });

        $response = $this->kernel->handle(Request::create('GET', '/boom'));

        $this->assertSame(500, $response->status);
        $this->assertStringNotContainsString('detalhe interno', $response->body);
        $this->assertCount(1, $this->logger->messages);
    }
}
