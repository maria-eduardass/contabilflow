<?php

declare(strict_types=1);

namespace ContabilFlow\Core;

interface LoggerInterface
{
    public function error(string $message): void;
}
