<?php

declare(strict_types=1);

namespace LogDB\Errors;

use Throwable;

/** Thrown when the server rejects the API key (HTTP 401 / 403). Not retried. */
final class LogDBAuthError extends LogDBError
{
    public function __construct(string $message = 'LogDB rejected the API key', ?Throwable $previous = null)
    {
        parent::__construct($message, $previous);
    }
}
