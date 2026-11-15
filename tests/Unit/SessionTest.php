<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Core\Session;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $data = [];

    private function session(): Session
    {
        return new Session($this->data);
    }

    public function testSetGetHasRemove(): void
    {
        $session = $this->session();

        $session->set('user_id', 7);

        $this->assertTrue($session->has('user_id'));
        $this->assertSame(7, $session->get('user_id'));
        $this->assertSame(7, $this->data['user_id']);

        $session->remove('user_id');
        $this->assertFalse($session->has('user_id'));
    }

    public function testGetReturnsDefaultWhenMissing(): void
    {
        $this->assertNull($this->session()->get('x'));
        $this->assertSame('d', $this->session()->get('x', 'd'));
    }

    public function testClearEmptiesUnderlyingStorage(): void
    {
        $session = $this->session();
        $session->set('a', 1);

        $session->clear();

        $this->assertSame([], $this->data);
    }

    public function testTouchFirstCallIsValidAndRecordsActivity(): void
    {
        $this->assertTrue($this->session()->touch(1000, 1800));
        $this->assertSame(1000, $this->data['_last_activity']);
    }

    public function testTouchWithinTimeoutKeepsDataAndRefreshesActivity(): void
    {
        $session = $this->session();
        $session->touch(1000, 1800);
        $session->set('user_id', 1);

        $this->assertTrue($session->touch(2000, 1800));
        $this->assertSame(1, $session->get('user_id'));
        $this->assertSame(2000, $this->data['_last_activity']);
    }

    public function testTouchExactlyAtTimeoutIsStillValid(): void
    {
        $session = $this->session();
        $session->touch(1000, 1800);

        $this->assertTrue($session->touch(2800, 1800));
    }

    public function testTouchAfterTimeoutClearsSession(): void
    {
        $session = $this->session();
        $session->touch(1000, 1800);
        $session->set('user_id', 1);

        $this->assertFalse($session->touch(2801, 1800));
        $this->assertFalse($session->has('user_id'));
        $this->assertSame(2801, $this->data['_last_activity']);
    }

    public function testCookieParamsAreHardened(): void
    {
        $params = Session::cookieParams(true);

        $this->assertTrue($params['httponly']);
        $this->assertTrue($params['secure']);
        $this->assertSame('Lax', $params['samesite']);
        $this->assertSame('/', $params['path']);
        $this->assertSame(0, $params['lifetime']);
    }

    public function testSecureFlagFollowsHttps(): void
    {
        $this->assertFalse(Session::cookieParams(false)['secure']);
    }

    public function testIsHttpsDetection(): void
    {
        $this->assertTrue(Session::isHttps(['HTTPS' => 'on']));
        $this->assertFalse(Session::isHttps(['HTTPS' => 'off']));
        $this->assertFalse(Session::isHttps(['HTTPS' => '']));
        $this->assertFalse(Session::isHttps([]));
    }
}
