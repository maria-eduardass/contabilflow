<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

use RuntimeException;

/** Operação proibida no estado atual da guia (transição inválida, edição de guia paga). Vira 422. */
final class InvalidStateException extends RuntimeException
{
}
