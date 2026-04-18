<?php

declare(strict_types=1);

namespace LogDB\Errors;

/** Thrown when configuration is invalid (HTTP 400 / missing endpoint / malformed URL). */
final class LogDBConfigError extends LogDBError
{
}
