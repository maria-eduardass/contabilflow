<?php

declare(strict_types=1);

namespace ContabilFlow\Core;

use ContabilFlow\Models\UserRepositoryInterface;

/**
 * Exige usuário logado em toda rota, exceto as públicas.
 *
 * Por quê: "negar por padrão" evita esquecer de proteger uma rota nova. Só os
 * caminhos listados (comparação exata, nunca prefixo) passam sem sessão.
 * Cliente de API (Accept: application/json) recebe 401; o navegador é
 * redirecionado para /login.
 *
 * Com o repositório de usuários, a cada requisição também confere se a conta
 * da sessão ainda existe e está ativa: desativar um usuário derruba a sessão
 * dele na hora, sem esperar o logout.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    /** @param list<string> $publicPaths */
    public function __construct(
        private Session $session,
        private array $publicPaths = ['/login'],
        private ?UserRepositoryInterface $users = null,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (in_array($request->path, $this->publicPaths, true)) {
            return $next($request);
        }

        $userId = $this->session->get('user_id');
        if (is_int($userId) && $this->isActive($userId)) {
            return $next($request);
        }
        if (is_int($userId)) {
            $this->session->clear();
        }

        if (str_contains($request->header('Accept') ?? '', 'application/json')) {
            return Response::json(
                ['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Faça login para continuar.']],
                401
            );
        }

        return Response::redirect('/login');
    }

    private function isActive(int $userId): bool
    {
        return $this->users === null || $this->users->find($userId)?->ativo === true;
    }
}
