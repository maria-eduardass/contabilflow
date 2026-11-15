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

    /** @return list<array{id: int, nome: string}> usuários ativos, por nome (opções de responsável) */
    public function listActive(): array;
}
