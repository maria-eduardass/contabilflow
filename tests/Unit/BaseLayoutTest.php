<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Core\View;
use PHPUnit\Framework\TestCase;

/** Testa o layout real em views/layout.php. */
final class BaseLayoutTest extends TestCase
{
    private function render(string $title): string
    {
        $dir = sys_get_temp_dir() . '/cf-views-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/page.php', '<h1>conteúdo</h1>');
        copy(__DIR__ . '/../../views/layout.php', $dir . '/layout.php');

        try {
            return (new View($dir))->render('page', ['title' => $title]);
        } finally {
            unlink($dir . '/page.php');
            unlink($dir . '/layout.php');
            rmdir($dir);
        }
    }

    public function testIsHtml5WithBootstrapServedLocally(): void
    {
        $html = $this->render('Painel');

        $this->assertStringStartsWith('<!doctype html>', $html);
        $this->assertStringContainsString('lang="pt-BR"', $html);
        $this->assertStringContainsString('name="viewport"', $html);
        $this->assertStringContainsString('/assets/vendor/bootstrap/bootstrap.min.css', $html);
        $this->assertStringContainsString('/assets/vendor/bootstrap/bootstrap.bundle.min.js', $html);
        $this->assertStringContainsString('<h1>conteúdo</h1>', $html);
    }

    public function testDoesNotLoadExternalResourcesOrInlineScripts(): void
    {
        $html = $this->render('Painel');

        $this->assertStringNotContainsString('http://', $html);
        $this->assertStringNotContainsString('https://', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)/i', $html);
        $this->assertStringNotContainsString(' style=', $html);
    }

    public function testEscapesTitle(): void
    {
        $html = $this->render('<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testBootstrapAssetsExist(): void
    {
        $base = __DIR__ . '/../../public/assets/vendor/bootstrap/';

        $this->assertFileExists($base . 'bootstrap.min.css');
        $this->assertFileExists($base . 'bootstrap.bundle.min.js');
    }
}
