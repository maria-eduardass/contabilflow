<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Core\Csrf;
use ContabilFlow\Core\Request;
use ContabilFlow\Core\Session;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $data = [];

    private function csrf(): Csrf
    {
        return new Csrf(new Session($this->data));
    }

    public function testTokenIsRandomHexAndStableWithinSession(): void
    {
        $csrf = $this->csrf();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $csrf->token());
        $this->assertSame($csrf->token(), $csrf->token());
    }

    public function testTokensDifferBetweenSessions(): void
    {
        $other = [];

        $this->assertNotSame($this->csrf()->token(), (new Csrf(new Session($other)))->token());
    }

    public function testAcceptsTokenFromFormField(): void
    {
        $csrf = $this->csrf();
        $request = Request::create('POST', '/x', ['_csrf' => $csrf->token()]);

        $this->assertTrue($csrf->isValid($request));
    }

    public function testAcceptsTokenFromHeader(): void
    {
        $csrf = $this->csrf();
        $request = Request::create('POST', '/x', [], ['X-CSRF-Token' => $csrf->token()]);

        $this->assertTrue($csrf->isValid($request));
    }

    public function testRejectsMissingToken(): void
    {
        $csrf = $this->csrf();
        $csrf->token();

        $this->assertFalse($csrf->isValid(Request::create('POST', '/x')));
    }

    public function testRejectsWrongOrTamperedToken(): void
    {
        $csrf = $this->csrf();
        $token = $csrf->token();

        $this->assertFalse($csrf->isValid(Request::create('POST', '/x', ['_csrf' => 'errado'])));
        $this->assertFalse($csrf->isValid(Request::create('POST', '/x', ['_csrf' => substr($token, 0, -1) . 'z'])));
        $this->assertFalse($csrf->isValid(Request::create('POST', '/x', ['_csrf' => $token . 'a'])));
    }

    public function testRejectsNonStringToken(): void
    {
        $csrf = $this->csrf();
        $csrf->token();

        $this->assertFalse($csrf->isValid(Request::create('POST', '/x', ['_csrf' => ['a']])));
    }

    public function testEmptyTokenNeverMatchesSessionWithoutToken(): void
    {
        $this->assertFalse($this->csrf()->isValid(Request::create('POST', '/x', ['_csrf' => ''])));
        $this->assertSame([], $this->data, 'validar não deve criar token');
    }

    public function testRotateInvalidatesOldToken(): void
    {
        $csrf = $this->csrf();
        $old = $csrf->token();

        $csrf->rotate();

        $this->assertNotSame($old, $csrf->token());
        $this->assertFalse($csrf->isValid(Request::create('POST', '/x', ['_csrf' => $old])));
    }

    public function testFieldRendersEscapedHiddenInput(): void
    {
        $csrf = $this->csrf();

        $this->assertSame(
            '<input type="hidden" name="_csrf" value="' . $csrf->token() . '">',
            $csrf->field()
        );
    }
}
