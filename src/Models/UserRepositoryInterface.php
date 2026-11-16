<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

use DateTimeImmutable;

interface UserRepositoryInterface
{
    public function findByEmail(string $email): ?Usuario;

    /** @return bool true se esta falha atingiu o limite e a conta foi bloqueada */
    public function registerFailure(int $userId, int $maxAttempts, DateTimeImmutable $lockedUntil): bool;

    public function resetFailures(int $userId): void;

    public function updatePasswordHash(int $userId, string $hash): void;

    public function find(int $id): ?Usuario;

    /**
     * Usuários por nome, 20 por página.
     *
     * @return Page<Usuario>
     */
    public function listPage(int $page): Page;

    /** @throws DuplicateException e-mail já cadastrado */
    public function create(string $nome, string $email, string $senhaHash, string $perfil): int;

    /** @return bool false se o usuário não existe */
    public function setActive(int $id, bool $ativo): bool;

    /** @return list<array{id: int, nome: string}> usuários ativos, por nome (opções de responsável) */
    public function listActive(): array;
}
