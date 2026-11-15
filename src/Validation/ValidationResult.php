<?php

declare(strict_types=1);

namespace ContabilFlow\Validation;

final class ValidationResult
{
    /**
     * @param array<string, string> $errors mensagem por campo (uma por campo)
     * @param array<string, string|null> $data só campos válidos, já normalizados
     */
    public function __construct(public readonly array $errors, public readonly array $data)
    {
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }
}
