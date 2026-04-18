<?php

declare(strict_types=1);

namespace LogDB\Errors;

/** Thrown when a network/transport error reaches the user (after retries are exhausted). */
final class LogDBNetworkError extends LogDBError
{
}
