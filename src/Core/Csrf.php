<?php

declare(strict_types=1);

namespace ContabilFlow\Core;

/**
 * Token CSRF por sessão.
 *
 * Por quê: hash_equals compara em tempo constante (sem vazar o token por
 * timing). Validar nunca cria token: sem token na sessão, nada é válido, o
 * que impede aceitar um valor vazio.
 */
final class Csrf
{
    private const KEY = '_csrf';

    public function __construct(private Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::KEY, $token);
        }

        return $token;
    }

    /** Gera um novo token (usar após login/logout). */
    public function rotate(): void
    {
        $this->session->remove(self::KEY);
    }

    public function isValid(Request $request): bool
    {
        $known = $this->session->get(self::KEY);
        $sent = $request->post['_csrf'] ?? $request->header('X-CSRF-Token');

        if (!is_string($known) || $known === '' || !is_string($sent)) {
            return false;
        }

        return hash_equals($known, $sent);
    }

    /** Campo oculto para formulários HTML. */
    public function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e($this->token()) . '">';
    }
}
