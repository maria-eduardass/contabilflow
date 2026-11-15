<?php

declare(strict_types=1);

namespace ContabilFlow\Core;

use Throwable;

/**
 * Converte exceções não tratadas em uma resposta 500 genérica.
 *
 * Por quê: o log leva classe, mensagem e local, mas NÃO o stack trace, porque
 * o trace pode incluir argumentos (ex.: senha em password_verify). O usuário
 * nunca vê detalhes, exceto com APP_DEBUG=true em ambiente local.
 */
final class ErrorHandler
{
    public function __construct(private LoggerInterface $logger, private bool $debug)
    {
    }

    public function handle(Throwable $e): Response
    {
        $details = sprintf('%s: %s em %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine());
        $this->logger->error($details);

        $body = '<h1>Erro interno do servidor</h1>';
        if ($this->debug) {
            $body .= '<pre>' . htmlspecialchars($details, ENT_QUOTES, 'UTF-8') . '</pre>';
        }

        return Response::html($body, 500);
    }
}
