<?php

declare(strict_types=1);

namespace Async\Spawn;

use Exception;

class SpawnError extends Exception
{
    public static function fromException($exception): self
    {
        return new self($exception);
    }
}
