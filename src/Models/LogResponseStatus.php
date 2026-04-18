<?php

declare(strict_types=1);

namespace LogDB\Models;

/** Result of a single log/batch send operation. */
enum LogResponseStatus: string
{
    case Success = 'Success';
    case Failed = 'Failed';
    case NotAuthorized = 'NotAuthorized';
    case CircuitOpen = 'CircuitOpen';
    case Timeout = 'Timeout';
}
