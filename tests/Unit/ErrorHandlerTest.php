<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Core\ErrorHandler;
use ContabilFlow\Tests\Support\ArrayLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ErrorHandlerTest extends TestCase
{
    public function testReturnsGenericBodyWithoutLeakingDetails(): void
    {
        $handler = new ErrorHandler(new ArrayLogger(), false);

        $response = $handler->handle(new RuntimeException('senha=segredo123'));

        $this->assertSame(500, $response->status);
        $this->assertStringNotContainsString('segredo123', $response->body);
        $this->assertStringContainsString('Erro interno', $response->body);
    }

    public function testLogsClassMessageAndLocation(): void
    {
        $logger = new ArrayLogger();

        (new ErrorHandler($logger, false))->handle(new RuntimeException('falhou'));

        $this->assertCount(1, $logger->messages);
        $this->assertStringContainsString('RuntimeException', $logger->messages[0]);
        $this->assertStringContainsString('falhou', $logger->messages[0]);
        $this->assertStringContainsString('ErrorHandlerTest.php', $logger->messages[0]);
    }

    public function testDebugModeShowsEscapedDetails(): void
    {
        $handler = new ErrorHandler(new ArrayLogger(), true);

        $response = $handler->handle(new RuntimeException('<script>x</script>'));

        $this->assertStringContainsString('&lt;script&gt;', $response->body);
        $this->assertStringNotContainsString('<script>', $response->body);
    }
}
