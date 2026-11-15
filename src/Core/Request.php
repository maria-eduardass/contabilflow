<?php

declare(strict_types=1);

namespace ContabilFlow\Core;

/**
 * Requisição HTTP imutável.
 *
 * Por quê: os navegadores só enviam GET e POST em formulários, então PUT,
 * PATCH e DELETE chegam como POST + campo _method. O override é aceito
 * SOMENTE em POST (um GET nunca pode virar DELETE por um link forjado) e
 * somente para esses três verbos.
 */
final class Request
{
    private const OVERRIDABLE = ['PUT', 'PATCH', 'DELETE'];

    /**
     * @param array<string, string> $query
     * @param array<string, mixed> $post
     * @param array<string, string> $headers chaves em minúsculas
     */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        private readonly array $headers,
    ) {
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, string> $headers
     */
    public static function create(string $method, string $uri, array $post = [], array $headers = []): self
    {
        $method = strtoupper($method);
        if ($method === 'POST' && isset($post['_method']) && is_string($post['_method'])) {
            $override = strtoupper($post['_method']);
            if (in_array($override, self::OVERRIDABLE, true)) {
                $method = $override;
            }
        }

        $path = (string) parse_url($uri, PHP_URL_PATH);
        $path = $path === '' ? '/' : ($path === '/' ? '/' : rtrim($path, '/'));

        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        /** @var array<string, string> $query */

        return new self($method, $path, $query, $post, array_change_key_case($headers, CASE_LOWER));
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $post
     */
    public static function fromGlobals(array $server, array $post): self
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (is_string($value) && str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            }
        }

        $method = is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET';
        $uri = is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';

        return self::create($method, $uri, $post, $headers);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
