<?php

declare(strict_types=1);

namespace ContabilFlow\Core;

use Throwable;

/**
 * Orquestra a requisição: middlewares, roteamento, erros e cabeçalhos de segurança.
 *
 * Por quê: o primeiro middleware da lista é o mais externo. Os cabeçalhos de
 * segurança são aplicados por fora de tudo, inclusive em respostas curto-circuitadas
 * (ex.: 419) e em erros 500.
 */
final class Kernel
{
    /** @param list<MiddlewareInterface> $middleware */
    public function __construct(
        private Router $router,
        private ErrorHandler $errors,
        private array $middleware = [],
    ) {
    }

    public function handle(Request $request): Response
    {
        $pipeline = array_reduce(
            array_reverse($this->middleware),
            static fn(callable $next, MiddlewareInterface $m): callable
                => static fn(Request $r): Response => $m->handle($r, $next),
            fn(Request $r): Response => $this->router->dispatch($r)
        );

        try {
            $response = $pipeline($request);
        } catch (Throwable $e) {
            $response = $this->errors->handle($e);
        }

        return SecurityHeaders::apply($response);
    }
}
