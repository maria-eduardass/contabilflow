<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

use RuntimeException;
use Throwable;

/** Violação de UNIQUE (MySQL 1062). Vira 409 com o erro no campo indicado. */
final class DuplicateException extends RuntimeException
{
    public function __construct(public readonly string $field, ?Throwable $previous = null)
    {
        parent::__construct("Valor duplicado no campo {$field}.", 0, $previous);
    }
}
