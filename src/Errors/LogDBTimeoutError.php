<?php

declare(strict_types=1);

namespace LogDB\Errors;

use Throwable;

/** Thrown when an HTTP request exceeds its deadline. */
final class LogDBTimeoutError extends LogDBError
{
    public function __construct(string $message = 'LogDB request exceeded its deadline', ?Throwable $previous = null)
    {
        parent::__construct($message, $previous);
    }
}
