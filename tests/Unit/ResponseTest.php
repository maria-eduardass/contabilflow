<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Core\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testHtmlResponse(): void
    {
        $response = Response::html('<p>oi</p>', 201);

        $this->assertSame(201, $response->status);
        $this->assertSame('<p>oi</p>', $response->body);
        $this->assertSame('text/html; charset=UTF-8', $response->headers['Content-Type']);
    }

    public function testJsonResponseKeepsUnicode(): void
    {
        $response = Response::json(['msg' => 'ação'], 422);

        $this->assertSame(422, $response->status);
        $this->assertSame('{"msg":"ação"}', $response->body);
        $this->assertSame('application/json; charset=UTF-8', $response->headers['Content-Type']);
    }

    public function testRedirect(): void
    {
        $response = Response::redirect('/login');

        $this->assertSame(302, $response->status);
        $this->assertSame('/login', $response->headers['Location']);
    }

    public function testWithHeadersReturnsCopyAndOverrides(): void
    {
        $original = Response::html('x');

        $copy = $original->withHeaders(['Retry-After' => '60', 'Content-Type' => 'text/plain']);

        $this->assertSame('60', $copy->headers['Retry-After']);
        $this->assertSame('text/plain', $copy->headers['Content-Type']);
        $this->assertArrayNotHasKey('Retry-After', $original->headers);
        $this->assertSame('x', $copy->body);
    }

    public function testSendPrintsBody(): void
    {
        ob_start();
        Response::html('corpo')->send();

        $this->assertSame('corpo', ob_get_clean());
    }
}
