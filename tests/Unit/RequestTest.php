<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Core\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testPostWithMethodOverrideBecomesPut(): void
    {
        $request = Request::create('POST', '/empresas/1', ['_method' => 'PUT']);

        $this->assertSame('PUT', $request->method);
    }

    public function testOverrideIsCaseInsensitive(): void
    {
        $this->assertSame('PATCH', Request::create('POST', '/x', ['_method' => 'patch'])->method);
    }

    public function testOverrideIgnoredOnGet(): void
    {
        $request = Request::create('GET', '/empresas/1', ['_method' => 'DELETE']);

        $this->assertSame('GET', $request->method);
    }

    public function testOverrideIgnoredWhenValueIsNotAllowed(): void
    {
        $this->assertSame('POST', Request::create('POST', '/x', ['_method' => 'GET'])->method);
        $this->assertSame('POST', Request::create('POST', '/x', ['_method' => 'TRACE'])->method);
        $this->assertSame('POST', Request::create('POST', '/x', ['_method' => ['PUT']])->method);
    }

    public function testNormalizesPathAndParsesQuery(): void
    {
        $request = Request::create('get', '/empresas/?q=padaria&page=2');

        $this->assertSame('GET', $request->method);
        $this->assertSame('/empresas', $request->path);
        $this->assertSame(['q' => 'padaria', 'page' => '2'], $request->query);
    }

    public function testRootPathStaysSlash(): void
    {
        $this->assertSame('/', Request::create('GET', '/')->path);
    }

    public function testHeadersAreCaseInsensitive(): void
    {
        $request = Request::create('POST', '/x', [], ['X-CSRF-Token' => 'abc']);

        $this->assertSame('abc', $request->header('x-csrf-token'));
        $this->assertNull($request->header('x-missing'));
    }

    public function testFromGlobalsReadsServerAndPost(): void
    {
        $server = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/login?a=1', 'HTTP_X_CSRF_TOKEN' => 't'];

        $request = Request::fromGlobals($server, ['email' => 'a@b.c']);

        $this->assertSame('POST', $request->method);
        $this->assertSame('/login', $request->path);
        $this->assertSame('1', $request->query['a']);
        $this->assertSame('a@b.c', $request->post['email']);
        $this->assertSame('t', $request->header('X-CSRF-Token'));
    }

    public function testFromGlobalsDefaultsToGetRoot(): void
    {
        $request = Request::fromGlobals([], []);

        $this->assertSame('GET', $request->method);
        $this->assertSame('/', $request->path);
    }
}
