<?php

declare(strict_types=1);

namespace LogDB\Errors;

use RuntimeException;
use Throwable;

/**
 * Base error type for everything thrown by `logdbhq/logdb-php`. Catch this to handle
 * any SDK-originated failure.
 */
class LogDBError extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
