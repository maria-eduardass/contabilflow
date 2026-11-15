<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Core\Env;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EnvTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'env');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function write(string $content): void
    {
        file_put_contents($this->file, $content);
    }

    public function testReadsKeyValuePairs(): void
    {
        $this->write("DB_HOST=127.0.0.1\nDB_PORT=3306\n");

        $env = Env::fromFile($this->file);

        $this->assertSame('127.0.0.1', $env->get('DB_HOST'));
        $this->assertSame('3306', $env->get('DB_PORT'));
    }

    public function testIgnoresCommentsAndBlankLines(): void
    {
        $this->write("# comentário\n\nA=1\n   # outro\n");

        $this->assertSame('1', Env::fromFile($this->file)->get('A'));
    }

    public function testStripsMatchingQuotesAndKeepsEqualsInValue(): void
    {
        $this->write("A=\"com espaço\"\nB='x'\nC=a=b=c\n");

        $env = Env::fromFile($this->file);

        $this->assertSame('com espaço', $env->get('A'));
        $this->assertSame('x', $env->get('B'));
        $this->assertSame('a=b=c', $env->get('C'));
    }

    public function testAllowsEmptyValue(): void
    {
        $this->write("DB_PASS=\n");

        $this->assertSame('', Env::fromFile($this->file, ['DB_PASS'])->get('DB_PASS'));
    }

    public function testFailsWhenFileDoesNotExist(): void
    {
        $this->expectException(RuntimeException::class);

        Env::fromFile($this->file . '.missing');
    }

    public function testFailsListingAllMissingRequiredKeys(): void
    {
        $this->write("A=1\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('B, C');

        Env::fromFile($this->file, ['A', 'B', 'C']);
    }

    public function testGetFailsForUnknownKey(): void
    {
        $this->write("A=1\n");

        $this->expectException(RuntimeException::class);

        Env::fromFile($this->file)->get('X');
    }

    public function testGetOrReturnsDefault(): void
    {
        $this->write("A=1\n");

        $this->assertSame('d', Env::fromFile($this->file)->getOr('X', 'd'));
    }
}
