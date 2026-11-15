<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Core\Request;
use ContabilFlow\Core\Response;
use ContabilFlow\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    public function testDispatchesStaticRoute(): void
    {
        $this->router->get('/empresas', fn() => Response::html('lista'));

        $response = $this->router->dispatch(Request::create('GET', '/empresas'));

        $this->assertSame(200, $response->status);
        $this->assertSame('lista', $response->body);
    }

    public function testPassesRouteParameters(): void
    {
        $this->router->get('/empresas/{id}/obrigacoes/{oid}', fn($r, $p) => Response::html($p['id'] . '-' . $p['oid']));

        $response = $this->router->dispatch(Request::create('GET', '/empresas/7/obrigacoes/9'));

        $this->assertSame('7-9', $response->body);
    }

    public function testParameterRegexConstraint(): void
    {
        $this->router->get('/empresas/{id:[0-9]+}', fn($r, $p) => Response::html($p['id']));

        $this->assertSame(200, $this->router->dispatch(Request::create('GET', '/empresas/12'))->status);
        $this->assertSame(404, $this->router->dispatch(Request::create('GET', '/empresas/abc'))->status);
    }

    public function testFirstRegisteredRouteWins(): void
    {
        $this->router->get('/empresas/nova', fn() => Response::html('form'));
        $this->router->get('/empresas/{id}', fn() => Response::html('detalhe'));

        $this->assertSame('form', $this->router->dispatch(Request::create('GET', '/empresas/nova'))->body);
        $this->assertSame('detalhe', $this->router->dispatch(Request::create('GET', '/empresas/5'))->body);
    }

    public function testUnknownPathReturns404(): void
    {
        $this->router->get('/a', fn() => Response::html('a'));

        $this->assertSame(404, $this->router->dispatch(Request::create('GET', '/b'))->status);
    }

    public function testWrongMethodReturns405WithAllowHeader(): void
    {
        $this->router->get('/empresas/{id}', fn() => Response::html('x'));
        $this->router->put('/empresas/{id}', fn() => Response::html('y'));

        $response = $this->router->dispatch(Request::create('DELETE', '/empresas/1'));

        $this->assertSame(405, $response->status);
        $this->assertSame('GET, PUT', $response->headers['Allow']);
    }

    public function testMethodOverrideReachesPutRoute(): void
    {
        $this->router->put('/empresas/{id}', fn() => Response::html('atualizada'));

        $response = $this->router->dispatch(Request::create('POST', '/empresas/1', ['_method' => 'PUT']));

        $this->assertSame('atualizada', $response->body);
    }

    public function testOverrideOnGetDoesNotReachDeleteRoute(): void
    {
        $this->router->delete('/empresas/{id}', fn() => Response::html('apagada'));

        $response = $this->router->dispatch(Request::create('GET', '/empresas/1', ['_method' => 'DELETE']));

        $this->assertSame(405, $response->status);
    }

    public function testLiteralCharactersInPatternAreEscaped(): void
    {
        $this->router->get('/api/v1.json', fn() => Response::html('ok'));

        $this->assertSame(404, $this->router->dispatch(Request::create('GET', '/api/v1xjson'))->status);
    }
}
