<?php

declare(strict_types=1);

namespace ContabilFlow\Core;

/**
 * Exige token CSRF em toda requisição que altera estado.
 *
 * Por quê: a verificação roda sobre o método JÁ resolvido (PUT/PATCH/DELETE
 * vindos de _method também são checados); só GET, HEAD e OPTIONS são isentos.
 * A resposta é 419, distinta de 403, para o usuário saber que basta recarregar.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private Csrf $csrf)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (in_array($request->method, self::SAFE_METHODS, true) || $this->csrf->isValid($request)) {
            return $next($request);
        }

        $message = 'Sessão expirada ou token inválido. Recarregue a página e tente novamente.';
        if (str_contains($request->header('Accept') ?? '', 'application/json')) {
            return Response::json(['error' => ['code' => 'CSRF_INVALID', 'message' => $message]], 419);
        }

        return Response::html('<h1>' . e($message) . '</h1>', 419);
    }
}
