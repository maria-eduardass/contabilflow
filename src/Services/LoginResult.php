<?php

declare(strict_types=1);

namespace ContabilFlow\Services;

use ContabilFlow\Models\Usuario;
use DateTimeImmutable;

final class LoginResult
{
    private function __construct(
        public readonly LoginStatus $status,
        public readonly ?Usuario $user = null,
        public readonly ?DateTimeImmutable $lockedUntil = null,
    ) {
    }

    public static function success(Usuario $user): self
    {
        return new self(LoginStatus::Success, $user);
    }

    public static function invalid(): self
    {
        return new self(LoginStatus::InvalidCredentials);
    }

    public static function locked(DateTimeImmutable $until): self
    {
        return new self(LoginStatus::Locked, null, $until);
    }
}
