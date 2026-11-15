<?php

declare(strict_types=1);

namespace ContabilFlow\Core;

interface MiddlewareInterface
{
    /** @param callable(Request): Response $next */
    public function handle(Request $request, callable $next): Response;
}
