<?php

declare(strict_types=1);

namespace ContabilFlow\Core;

use Closure;

/** Expira a sessão após inatividade (padrão 30 min) antes de qualquer outra verificação. */
final class SessionMiddleware implements MiddlewareInterface
{
    private Closure $clock;

    public function __construct(
        private Session $session,
        private int $idleTimeout = 1800,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function handle(Request $request, callable $next): Response
    {
        $this->session->touch(($this->clock)(), $this->idleTimeout);

        return $next($request);
    }
}
