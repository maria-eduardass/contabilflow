<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

use RuntimeException;

/** Operação bloqueada por vínculo (FK RESTRICT, MySQL 1451). Vira 409. */
class ConflictException extends RuntimeException
{
}
