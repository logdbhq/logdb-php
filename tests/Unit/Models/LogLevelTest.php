<?php

declare(strict_types=1);

namespace LogDB\Tests\Unit\Models;

use LogDB\Models\LogLevel;
use PHPUnit\Framework\TestCase;

final class LogLevelTest extends TestCase
{
    public function testValuesMatchOtherSdks(): void
    {
        $this->assertSame(0, LogLevel::Trace->value);
        $this->assertSame(1, LogLevel::Debug->value);
        $this->assertSame(2, LogLevel::Info->value);
        $this->assertSame(3, LogLevel::Warning->value);
        $this->assertSame(4, LogLevel::Error->value);
        $this->assertSame(5, LogLevel::Critical->value);
        $this->assertSame(6, LogLevel::Exception->value);
    }

    public function testToOtelSeverityMapsToOtelRanges(): void
    {
        $this->assertSame(1, LogLevel::Trace->toOtelSeverity());
        $this->assertSame(5, LogLevel::Debug->toOtelSeverity());
        $this->assertSame(9, LogLevel::Info->toOtelSeverity());
        $this->assertSame(13, LogLevel::Warning->toOtelSeverity());
        $this->assertSame(17, LogLevel::Error->toOtelSeverity());
        $this->assertSame(21, LogLevel::Critical->toOtelSeverity());
        $this->assertSame(21, LogLevel::Exception->toOtelSeverity());
    }

    public function testToStringRoundTripsExpectedNames(): void
    {
        $this->assertSame('Info', LogLevel::Info->toString());
        $this->assertSame('Warning', LogLevel::Warning->toString());
        $this->assertSame('Error', LogLevel::Error->toString());
        $this->assertSame('Critical', LogLevel::Critical->toString());
    }
}
