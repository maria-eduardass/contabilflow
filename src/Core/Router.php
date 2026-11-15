<?php

declare(strict_types=1);

namespace ContabilFlow\Core;

/**
 * Roteador por método + padrão, ex.: /empresas/{id} ou /empresas/{id:[0-9]+}.
 *
 * Por quê: vale a primeira rota registrada (registre /empresas/nova antes de
 * /empresas/{id}). Se o caminho existe mas o método não, responde 405 com
 * Allow, em vez de 404, para o erro ser diagnosticável.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: callable}> */
    private array $routes = [];

    /** @param callable(Request, array<string, string>): Response $handler */
    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = preg_replace_callback(
            '#\{(\w+)(?::([^}]+))?\}|[^{]+#',
            static fn(array $m): string => isset($m[1])
                ? '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')'
                : preg_quote($m[0], '#'),
            $pattern
        );

        $this->routes[] = ['method' => strtoupper($method), 'regex' => '#^' . $regex . '$#', 'handler' => $handler];
    }

    /** @param callable(Request, array<string, string>): Response $handler */
    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    /** @param callable(Request, array<string, string>): Response $handler */
    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    /** @param callable(Request, array<string, string>): Response $handler */
    public function put(string $pattern, callable $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    /** @param callable(Request, array<string, string>): Response $handler */
    public function patch(string $pattern, callable $handler): void
    {
        $this->add('PATCH', $pattern, $handler);
    }

    /** @param callable(Request, array<string, string>): Response $handler */
    public function delete(string $pattern, callable $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $matches) !== 1) {
                continue;
            }
            if ($route['method'] === $request->method) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                return ($route['handler'])($request, $params);
            }
            $allowed[$route['method']] = true;
        }

        if ($allowed !== []) {
            $methods = array_keys($allowed);
            sort($methods);

            return new Response('Método não permitido', 405, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Allow' => implode(', ', $methods),
            ]);
        }

        return Response::html('Página não encontrada', 404);
    }
}
