<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FrontControllerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    public function testFrontControllerProtectsRootWhenAnonymous(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        ob_start();
        require __DIR__ . '/../../public/index.php';
        $output = ob_get_clean();

        // Sem sessão, o AuthMiddleware redireciona (corpo vazio) em vez de servir a página.
        $this->assertSame('', $output);
    }
}
