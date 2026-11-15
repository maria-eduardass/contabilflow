<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Support;

use ContabilFlow\Core\LoggerInterface;

final class ArrayLogger implements LoggerInterface
{
    /** @var list<string> */
    public array $messages = [];

    public function error(string $message): void
    {
        $this->messages[] = $message;
    }
}
