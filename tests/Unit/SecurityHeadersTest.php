<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Core\ErrorHandler;
use ContabilFlow\Core\Kernel;
use ContabilFlow\Core\Request;
use ContabilFlow\Core\Response;
use ContabilFlow\Core\Router;
use ContabilFlow\Core\SecurityHeaders;
use ContabilFlow\Tests\Support\ArrayLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SecurityHeadersTest extends TestCase
{
    public function testAddsAllSecurityHeaders(): void
    {
        $headers = SecurityHeaders::apply(Response::html('x'))->headers;

        $this->assertSame('DENY', $headers['X-Frame-Options']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy']);
        $this->assertStringContainsString("default-src 'self'", $headers['Content-Security-Policy']);
        $this->assertStringContainsString("frame-ancestors 'none'", $headers['Content-Security-Policy']);
        $this->assertStringContainsString("form-action 'self'", $headers['Content-Security-Policy']);
    }

    public function testCspDoesNotAllowInlineOrExternalScripts(): void
    {
        $csp = SecurityHeaders::apply(Response::html('x'))->headers['Content-Security-Policy'];

        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->assertStringNotContainsString('http', $csp);
    }

    public function testKeepsBodyStatusAndExistingHeaders(): void
    {
        $response = SecurityHeaders::apply(Response::redirect('/login', 302));

        $this->assertSame(302, $response->status);
        $this->assertSame('/login', $response->headers['Location']);
    }

    public function testResponseHeaderTakesPrecedenceOverDefault(): void
    {
        $response = new Response('x', 200, ['X-Frame-Options' => 'SAMEORIGIN']);

        $this->assertSame('SAMEORIGIN', SecurityHeaders::apply($response)->headers['X-Frame-Options']);
    }

    public function testKernelAppliesHeadersToSuccess404And500(): void
    {
        $router = new Router();
        $router->get('/ok', fn() => Response::html('ok'));
        $router->get('/boom', function (): Response {
            throw new RuntimeException('x');
        });
        $kernel = new Kernel($router, new ErrorHandler(new ArrayLogger(), false));

        foreach (['/ok' => 200, '/nada' => 404, '/boom' => 500] as $path => $status) {
            $response = $kernel->handle(Request::create('GET', $path));

            $this->assertSame($status, $response->status);
            $this->assertSame('nosniff', $response->headers['X-Content-Type-Options'], $path);
        }
    }
}
