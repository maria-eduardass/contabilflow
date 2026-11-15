<?php

declare(strict_types=1);

namespace ContabilFlow\Core;

/**
 * Restringe caminhos a determinados perfis (403 para os demais).
 *
 * Por quê: as regras ficam num mapa único "prefixo => perfis permitidos", em vez
 * de espalhadas pelos controllers. O prefixo vale para o caminho exato e seus
 * subcaminhos ("/usuarios" cobre "/usuarios/3/ativo", mas não "/usuarios-x").
 * Caminho sem regra é liberado a qualquer usuário logado; perfil ausente ou
 * desconhecido nunca passa em caminho restrito. Roda depois do AuthMiddleware.
 */
final class RoleMiddleware implements MiddlewareInterface
{
    /** @param array<string, list<string>> $rules prefixo de caminho => perfis permitidos */
    public function __construct(private Session $session, private array $rules)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $allowed = $this->allowedRoles($request->path);
        if ($allowed === null) {
            return $next($request);
        }

        $perfil = $this->session->get('perfil');
        if (is_string($perfil) && in_array($perfil, $allowed, true)) {
            return $next($request);
        }

        $message = 'Você não tem permissão para acessar este recurso.';
        if (str_contains($request->header('Accept') ?? '', 'application/json')) {
            return Response::json(['error' => ['code' => 'FORBIDDEN', 'message' => $message]], 403);
        }

        return Response::html('<h1>' . e($message) . '</h1>', 403);
    }

    /** @return list<string>|null null quando o caminho não tem restrição */
    private function allowedRoles(string $path): ?array
    {
        foreach ($this->rules as $prefix => $roles) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return $roles;
            }
        }

        return null;
    }
}
