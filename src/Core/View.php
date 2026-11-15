<?php

declare(strict_types=1);

namespace ContabilFlow\Core;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Renderiza views PHP dentro de um layout.
 *
 * Por quê: o nome do template é validado por whitelist de caracteres para
 * impedir path traversal; o template roda numa closure estática, sem acesso a
 * $this nem a variáveis do chamador; e $content (HTML já escapado pelo
 * template) tem prioridade sobre qualquer dado com o mesmo nome.
 */
final class View
{
    public function __construct(private string $directory)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @return string HTML; $layout = null renderiza só o template
     */
    public function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $content = $this->capture($template, $data);

        return $layout === null
            ? $content
            : $this->capture($layout, ['content' => $content] + $data);
    }

    /** @param array<string, mixed> $data */
    private function capture(string $name, array $data): string
    {
        if (preg_match('#^[a-z0-9_-]+(/[a-z0-9_-]+)*$#i', $name) !== 1) {
            throw new InvalidArgumentException('Nome de template inválido.');
        }
        $file = $this->directory . '/' . $name . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("Template não encontrado: {$name}");
        }

        return (static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                include $__file;
            } catch (Throwable $e) {
                ob_end_clean();
                throw $e;
            }

            return (string) ob_get_clean();
        })($file, $data);
    }
}
