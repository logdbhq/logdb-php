<?php

declare(strict_types=1);

namespace LogDB\Errors;

/** Thrown synchronously when the circuit breaker is open. */
final class LogDBCircuitOpenError extends LogDBError
{
    public function __construct(string $message = 'LogDB circuit breaker is open')
    {
        parent::__construct($message);
    }
}
