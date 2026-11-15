<?php

declare(strict_types=1);

namespace ContabilFlow\Core;

/**
 * Log em arquivo, uma linha por evento.
 *
 * Por quê: quebras de linha da mensagem são removidas para ninguém forjar
 * entradas falsas de log, e falha de escrita nunca derruba a requisição.
 */
final class FileLogger implements LoggerInterface
{
    public function __construct(private string $path)
    {
    }

    public function error(string $message): void
    {
        $message = str_replace(["\r", "\n"], ' ', $message);
        @file_put_contents(
            $this->path,
            '[' . date('Y-m-d H:i:s') . '] ERROR ' . $message . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
