<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Core\FileLogger;
use PHPUnit\Framework\TestCase;

final class FileLoggerTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/cf-log-' . bin2hex(random_bytes(4)) . '.log';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testAppendsOneLinePerMessage(): void
    {
        $logger = new FileLogger($this->file);

        $logger->error('primeiro');
        $logger->error('segundo');

        $lines = file($this->file, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('ERROR primeiro', $lines[0]);
        $this->assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', $lines[0]);
    }

    public function testNewlinesInMessageCannotForgeLogLines(): void
    {
        (new FileLogger($this->file))->error("a\n[2000-01-01 00:00:00] ERROR forjado");

        $lines = file($this->file, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);
        $this->assertCount(1, $lines);
    }

    public function testDoesNotThrowWhenFileIsNotWritable(): void
    {
        (new FileLogger('/caminho/inexistente/app.log'))->error('x');

        $this->addToAssertionCount(1);
    }
}
