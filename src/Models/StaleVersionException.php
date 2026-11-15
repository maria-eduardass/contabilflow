<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

/** O registro foi alterado por outra pessoa desde que o formulário foi aberto (lock otimista). */
final class StaleVersionException extends ConflictException
{
}
