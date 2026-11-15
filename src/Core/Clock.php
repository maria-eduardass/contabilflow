<?php

declare(strict_types=1);

namespace ContabilFlow\Core;

use DateTimeImmutable;

/** Por quê: injetar o relógio torna bloqueio de login e semáforo testáveis sem depender da hora real. */
interface Clock
{
    public function now(): DateTimeImmutable;
}
