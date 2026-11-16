<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Support\Http;

/**
 * Catálogo de TODAS as rotas do sistema, com quem pode acessar cada uma.
 * O RouteSecurityTest confere que ele é idêntico às rotas registradas no
 * Router, então rota nova sem teste quebra a suíte.
 *
 * Acesso: public (sem login), user (admin ou contador) ou admin (só administrador).
 * Os caminhos de exemplo usam ids do seed (empresa 1, guia 1, usuário 2).
 */
final class RouteCatalog
{
    public const PUBLIC = 'public';
    public const USER = 'user';
    public const ADMIN = 'admin';

    /** @var list<array{method: string, pattern: string, uri: string, access: string, json?: bool}> */
    public const ROUTES = [
        ['method' => 'GET', 'pattern' => '/', 'uri' => '/', 'access' => self::USER],
        ['method' => 'GET', 'pattern' => '/dashboard', 'uri' => '/dashboard', 'access' => self::USER],
        ['method' => 'GET', 'pattern' => '/api/dashboard/indicadores', 'uri' => '/api/dashboard/indicadores', 'access' => self::USER, 'json' => true],

        ['method' => 'GET', 'pattern' => '/login', 'uri' => '/login', 'access' => self::PUBLIC],
        ['method' => 'POST', 'pattern' => '/login', 'uri' => '/login', 'access' => self::PUBLIC],
        ['method' => 'POST', 'pattern' => '/logout', 'uri' => '/logout', 'access' => self::USER],

        ['method' => 'GET', 'pattern' => '/empresas', 'uri' => '/empresas', 'access' => self::USER],
        ['method' => 'GET', 'pattern' => '/empresas/nova', 'uri' => '/empresas/nova', 'access' => self::USER],
        ['method' => 'POST', 'pattern' => '/empresas', 'uri' => '/empresas', 'access' => self::USER],
        ['method' => 'GET', 'pattern' => '/empresas/{id:[0-9]+}', 'uri' => '/empresas/1', 'access' => self::USER],
        ['method' => 'GET', 'pattern' => '/empresas/{id:[0-9]+}/editar', 'uri' => '/empresas/1/editar', 'access' => self::USER],
        ['method' => 'PUT', 'pattern' => '/empresas/{id:[0-9]+}', 'uri' => '/empresas/1', 'access' => self::USER],
        ['method' => 'DELETE', 'pattern' => '/empresas/{id:[0-9]+}', 'uri' => '/empresas/1', 'access' => self::USER],

        ['method' => 'GET', 'pattern' => '/obrigacoes', 'uri' => '/obrigacoes', 'access' => self::USER],
        ['method' => 'GET', 'pattern' => '/obrigacoes/nova', 'uri' => '/obrigacoes/nova', 'access' => self::USER],
        ['method' => 'POST', 'pattern' => '/obrigacoes', 'uri' => '/obrigacoes', 'access' => self::USER],
        ['method' => 'GET', 'pattern' => '/obrigacoes/{id:[0-9]+}', 'uri' => '/obrigacoes/1', 'access' => self::USER],
        ['method' => 'GET', 'pattern' => '/obrigacoes/{id:[0-9]+}/editar', 'uri' => '/obrigacoes/1/editar', 'access' => self::USER],
        ['method' => 'PUT', 'pattern' => '/obrigacoes/{id:[0-9]+}', 'uri' => '/obrigacoes/1', 'access' => self::USER],
        ['method' => 'PATCH', 'pattern' => '/obrigacoes/{id:[0-9]+}/status', 'uri' => '/obrigacoes/1/status', 'access' => self::USER],
        ['method' => 'DELETE', 'pattern' => '/obrigacoes/{id:[0-9]+}', 'uri' => '/obrigacoes/1', 'access' => self::USER],

        ['method' => 'GET', 'pattern' => '/api/obrigacoes/{id:[0-9]+}/lembrete', 'uri' => '/api/obrigacoes/1/lembrete', 'access' => self::USER, 'json' => true],
        ['method' => 'POST', 'pattern' => '/api/obrigacoes/{id:[0-9]+}/lembretes', 'uri' => '/api/obrigacoes/1/lembretes', 'access' => self::USER, 'json' => true],

        ['method' => 'GET', 'pattern' => '/usuarios', 'uri' => '/usuarios', 'access' => self::ADMIN],
        ['method' => 'GET', 'pattern' => '/usuarios/novo', 'uri' => '/usuarios/novo', 'access' => self::ADMIN],
        ['method' => 'POST', 'pattern' => '/usuarios', 'uri' => '/usuarios', 'access' => self::ADMIN],
        ['method' => 'PATCH', 'pattern' => '/usuarios/{id:[0-9]+}/ativo', 'uri' => '/usuarios/2/ativo', 'access' => self::ADMIN],

        ['method' => 'GET', 'pattern' => '/avaliacao', 'uri' => '/avaliacao', 'access' => self::USER],
        ['method' => 'POST', 'pattern' => '/avaliacao', 'uri' => '/avaliacao', 'access' => self::USER],
        ['method' => 'GET', 'pattern' => '/avaliacoes/resumo', 'uri' => '/avaliacoes/resumo', 'access' => self::ADMIN],
    ];

    /** @return array<string, array{0: array{method: string, pattern: string, uri: string, access: string, json?: bool}}> */
    public static function provider(?string $access = null, bool $onlyUnsafe = false): array
    {
        $cases = [];
        foreach (self::ROUTES as $route) {
            if ($access !== null && $route['access'] !== $access) {
                continue;
            }
            if ($onlyUnsafe && $route['method'] === 'GET') {
                continue;
            }
            $cases[$route['method'] . ' ' . $route['uri']] = [$route];
        }

        return $cases;
    }

    /** @return array<string, array{0: array{method: string, pattern: string, uri: string, access: string, json?: bool}}> */
    public static function nonPublic(): array
    {
        return array_filter(
            self::provider(),
            static fn(array $case): bool => $case[0]['access'] !== self::PUBLIC
        );
    }

    /** @return array<string, array{0: array{method: string, pattern: string, uri: string, access: string, json?: bool}}> */
    public static function adminOnly(): array
    {
        return self::provider(self::ADMIN);
    }

    /** @return array<string, array{0: array{method: string, pattern: string, uri: string, access: string, json?: bool}}> */
    public static function unsafeNonPublic(): array
    {
        return array_filter(
            self::provider(null, true),
            static fn(array $case): bool => $case[0]['access'] !== self::PUBLIC
        );
    }

    /** @return array<string, array{0: array{method: string, pattern: string, uri: string, access: string, json?: bool}}> */
    public static function all(): array
    {
        return self::provider();
    }
}
