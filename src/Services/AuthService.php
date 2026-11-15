<?php

declare(strict_types=1);

namespace ContabilFlow\Services;

use ContabilFlow\Core\Clock;
use ContabilFlow\Models\UserRepositoryInterface;

/**
 * Regras de login. Não conhece HTTP nem sessão.
 *
 * Por quê:
 * - E-mail inexistente, senha errada e usuário inativo dão o MESMO resultado
 *   (não revela quais e-mails existem).
 * - Para e-mail inexistente ainda se calcula um hash, para o tempo de resposta
 *   não denunciar que o usuário não existe.
 * - Durante o bloqueio a senha nem é verificada: nem a correta entra, e
 *   tentativas não estendem o bloqueio.
 * - Trade-off conhecido: o estado "bloqueada" só existe para e-mails reais,
 *   o que revela a existência dessas contas; aceito por exigência do requisito.
 */
final class AuthService
{
    public const MAX_ATTEMPTS = 5;
    public const LOCK_MINUTES = 15;

    public function __construct(private UserRepositoryInterface $users, private Clock $clock)
    {
    }

    public function login(string $email, string $password): LoginResult
    {
        $now = $this->clock->now();
        $user = $this->users->findByEmail(strtolower(trim($email)));

        if ($user === null) {
            password_hash($password, PASSWORD_DEFAULT);

            return LoginResult::invalid();
        }

        if ($user->isLockedAt($now)) {
            return LoginResult::locked($user->bloqueadoAte ?? $now);
        }

        if ($password === '' || !password_verify($password, $user->senhaHash)) {
            $until = $now->modify('+' . self::LOCK_MINUTES . ' minutes');

            return $this->users->registerFailure($user->id, self::MAX_ATTEMPTS, $until)
                ? LoginResult::locked($until)
                : LoginResult::invalid();
        }

        if (!$user->ativo) {
            return LoginResult::invalid();
        }

        $this->users->resetFailures($user->id);
        if (password_needs_rehash($user->senhaHash, PASSWORD_DEFAULT)) {
            $this->users->updatePasswordHash($user->id, password_hash($password, PASSWORD_DEFAULT));
        }

        return LoginResult::success($user);
    }
}
