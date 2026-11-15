<?php

declare(strict_types=1);

namespace ContabilFlow\Core;

/**
 * Cabeçalhos de segurança aplicados a TODA resposta pelo Kernel.
 *
 * Por quê: a CSP restrita ('self', sem inline) limita o estrago de um XSS que
 * escape da filtragem; por isso o Bootstrap é servido localmente e as views
 * não usam <script> nem style inline. Um cabeçalho já definido pela resposta
 * tem precedência sobre o padrão.
 */
final class SecurityHeaders
{
    private const DEFAULTS = [
        'Content-Security-Policy' => "default-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];

    public static function apply(Response $response): Response
    {
        return new Response($response->body, $response->status, $response->headers + self::DEFAULTS);
    }
}
