<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Core\Session;
use PHPUnit\Framework\TestCase;

final class SessionFlashTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function testFlashIsReadOnceAndThenGone(): void
    {
        $session = new Session($this->data);
        $session->flash('success', 'Salvo');
        $session->flash('danger', 'Erro');

        $this->assertSame(
            [['type' => 'success', 'message' => 'Salvo'], ['type' => 'danger', 'message' => 'Erro']],
            $session->pullFlash()
        );
        $this->assertSame([], $session->pullFlash());
    }

    public function testPullWithoutFlashReturnsEmptyList(): void
    {
        $this->assertSame([], (new Session($this->data))->pullFlash());
    }

    public function testCorruptedFlashDataIsIgnored(): void
    {
        $this->data['_flash'] = 'lixo';

        $this->assertSame([], (new Session($this->data))->pullFlash());
    }
}
