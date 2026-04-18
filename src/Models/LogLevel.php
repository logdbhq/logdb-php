<?php

declare(strict_types=1);

namespace LogDB\Models;

/**
 * Severity level for a log entry. Numeric values match the .NET / Node / Web SDKs
 * and align with OTel SeverityNumber ranges.
 */
enum LogLevel: int
{
    case Trace = 0;
    case Debug = 1;
    case Info = 2;
    case Warning = 3;
    case Error = 4;
    case Critical = 5;
    case Exception = 6;

    /** Canonical wire-format string LogDB uses ("Info", "Error", ...). */
    public function toString(): string
    {
        return match ($this) {
            self::Trace => 'Trace',
            self::Debug => 'Debug',
            self::Info => 'Info',
            self::Warning => 'Warning',
            self::Error => 'Error',
            self::Critical => 'Critical',
            self::Exception => 'Exception',
        };
    }

    /**
     * Map LogLevel → OTel SeverityNumber (used in OTLP wire format).
     *
     * @see https://opentelemetry.io/docs/specs/otel/logs/data-model/#field-severitynumber
     */
    public function toOtelSeverity(): int
    {
        return match ($this) {
            self::Trace => 1,
            self::Debug => 5,
            self::Info => 9,
            self::Warning => 13,
            self::Error => 17,
            self::Critical, self::Exception => 21,
        };
    }
}
