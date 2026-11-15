<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Core\View;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ViewTest extends TestCase
{
    private View $view;

    protected function setUp(): void
    {
        $this->view = new View(__DIR__ . '/../fixtures/views');
    }

    public function testRendersTemplateInsideLayout(): void
    {
        $html = $this->view->render('hello', ['name' => 'Maria', 'title' => 'Início']);

        $this->assertStringContainsString('<title>Início</title>', $html);
        $this->assertStringContainsString('<main><p>Olá, Maria</p>', $html);
    }

    public function testScriptInDataIsEscapedInTemplateAndTitle(): void
    {
        $html = $this->view->render('hello', ['name' => '<script>alert(1)</script>', 'title' => '<b>x</b>']);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
    }

    public function testCanRenderWithoutLayout(): void
    {
        $html = $this->view->render('hello', ['name' => 'Ana'], null);

        $this->assertSame("<p>Olá, Ana</p>\n", $html);
    }

    public function testDataCannotOverrideRenderedContentInLayout(): void
    {
        $html = $this->view->render('hello', ['name' => 'Ana', 'content' => '<i>forjado</i>']);

        $this->assertStringNotContainsString('<i>forjado</i>', $html);
        $this->assertStringContainsString('Olá, Ana', $html);
    }

    public function testMissingTemplateThrows(): void
    {
        $this->expectException(RuntimeException::class);

        $this->view->render('nao-existe');
    }

    /** @return array<string, array{string}> */
    public static function unsafeNames(): array
    {
        return [
            'parent dir' => ['../secret'],
            'nested parent' => ['a/../../secret'],
            'absolute' => ['/etc/passwd'],
            'extension' => ['hello.php'],
            'null byte' => ["hello\0"],
            'empty' => [''],
            'backslash' => ['a\\b'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeNames')]
    public function testRejectsUnsafeTemplateNames(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->view->render($name, [], null);
    }

    public function testExceptionInTemplateDoesNotLeakPartialOutput(): void
    {
        $level = ob_get_level();

        try {
            $this->view->render('boom', [], null);
            $this->fail('Deveria lançar exceção');
        } catch (RuntimeException $e) {
            $this->assertSame('falha no template', $e->getMessage());
        }

        $this->assertSame($level, ob_get_level());
    }
}
