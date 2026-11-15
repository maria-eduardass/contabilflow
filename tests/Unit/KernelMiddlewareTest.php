<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ArrayObject;
use ContabilFlow\Core\ErrorHandler;
use ContabilFlow\Core\Kernel;
use ContabilFlow\Core\MiddlewareInterface;
use ContabilFlow\Core\Request;
use ContabilFlow\Core\Response;
use ContabilFlow\Core\Router;
use ContabilFlow\Tests\Support\ArrayLogger;
use PHPUnit\Framework\TestCase;

final class KernelMiddlewareTest extends TestCase
{
    /** @var ArrayObject<int, string> */
    private ArrayObject $trace;

    protected function setUp(): void
    {
        $this->trace = new ArrayObject();
    }

    private function tracing(string $name): MiddlewareInterface
    {
        return new class ($name, $this->trace) implements MiddlewareInterface {
            /** @param ArrayObject<int, string> $trace */
            public function __construct(private string $name, private ArrayObject $trace)
            {
            }

            public function handle(Request $request, callable $next): Response
            {
                $this->trace->append($this->name . ':in');
                $response = $next($request);
                $this->trace->append($this->name . ':out');

                return $response;
            }
        };
    }

    private function blocking(): MiddlewareInterface
    {
        return new class implements MiddlewareInterface {
            public function handle(Request $request, callable $next): Response
            {
                return new Response('bloqueado', 419);
            }
        };
    }

    private function kernel(MiddlewareInterface ...$middleware): Kernel
    {
        $router = new Router();
        $router->get('/', function (): Response {
            $this->trace->append('handler');

            return Response::html('ok');
        });

        return new Kernel($router, new ErrorHandler(new ArrayLogger(), false), array_values($middleware));
    }

    public function testMiddlewareRunsInOrderAroundHandler(): void
    {
        $this->kernel($this->tracing('A'), $this->tracing('B'))->handle(Request::create('GET', '/'));

        $this->assertSame(['A:in', 'B:in', 'handler', 'B:out', 'A:out'], $this->trace->getArrayCopy());
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $response = $this->kernel($this->blocking(), $this->tracing('B'))->handle(Request::create('GET', '/'));

        $this->assertSame(419, $response->status);
        $this->assertSame([], $this->trace->getArrayCopy());
    }

    public function testShortCircuitedResponseStillGetsSecurityHeaders(): void
    {
        $response = $this->kernel($this->blocking())->handle(Request::create('GET', '/'));

        $this->assertSame('nosniff', $response->headers['X-Content-Type-Options']);
    }
}
