<?php

declare(strict_types=1);

namespace ContabilFlow\Core;

/**
 * Exige usuário logado em toda rota, exceto as públicas.
 *
 * Por quê: "negar por padrão" evita esquecer de proteger uma rota nova. Só os
 * caminhos listados (comparação exata, nunca prefixo) passam sem sessão.
 * Cliente de API (Accept: application/json) recebe 401; o navegador é
 * redirecionado para /login.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    /** @param list<string> $publicPaths */
    public function __construct(private Session $session, private array $publicPaths = ['/login'])
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (in_array($request->path, $this->publicPaths, true) || is_int($this->session->get('user_id'))) {
            return $next($request);
        }

        if (str_contains($request->header('Accept') ?? '', 'application/json')) {
            return Response::json(
                ['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Faça login para continuar.']],
                401
            );
        }

        return Response::redirect('/login');
    }
}
