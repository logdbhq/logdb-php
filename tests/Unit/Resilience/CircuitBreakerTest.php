<?php

declare(strict_types=1);

namespace LogDB\Tests\Unit\Resilience;

use LogDB\Errors\LogDBCircuitOpenError;
use LogDB\Errors\LogDBNetworkError;
use LogDB\Resilience\CircuitBreaker;
use PHPUnit\Framework\TestCase;

final class CircuitBreakerTest extends TestCase
{
    public function testStaysClosedBelowMinimumThroughput(): void
    {
        $now = 0.0;
        $breaker = new CircuitBreaker(
            failureThreshold: 0.5,
            samplingDurationMs: 10_000,
            durationOfBreakMs: 30_000,
            minimumThroughput: 5,
            clock: function () use (&$now): float {
                return $now;
            },
        );

        // Two failures — below the throughput floor.
        for ($i = 0; $i < 2; $i++) {
            try {
                $breaker->execute(static fn () => throw new LogDBNetworkError('x'));
            } catch (LogDBNetworkError) {
            }
        }

        $this->assertSame(CircuitBreaker::STATE_CLOSED, $breaker->getState());
    }

    public function testTripsOpenWhenFailureRateExceedsThreshold(): void
    {
        $now = 0.0;
        $breaker = new CircuitBreaker(
            failureThreshold: 0.5,
            samplingDurationMs: 10_000,
            durationOfBreakMs: 30_000,
            minimumThroughput: 5,
            clock: function () use (&$now): float {
                return $now;
            },
        );

        // 5 calls, 3 failures → 60% failure rate → trip
        for ($i = 0; $i < 3; $i++) {
            try {
                $breaker->execute(static fn () => throw new LogDBNetworkError('x'));
            } catch (LogDBNetworkError) {
            }
        }
        $breaker->execute(static fn () => 'ok');
        $breaker->execute(static fn () => 'ok');

        $this->assertSame(CircuitBreaker::STATE_OPEN, $breaker->getState());

        $this->expectException(LogDBCircuitOpenError::class);
        $breaker->execute(static fn () => 'should not run');
    }

    public function testTransitionsToHalfOpenAfterDurationOfBreak(): void
    {
        $now = 0.0;
        $breaker = new CircuitBreaker(
            failureThreshold: 0.5,
            samplingDurationMs: 10_000,
            durationOfBreakMs: 5_000,
            minimumThroughput: 2,
            clock: function () use (&$now): float {
                return $now;
            },
        );

        // Trip
        try {
            $breaker->execute(static fn () => throw new LogDBNetworkError('x'));
        } catch (LogDBNetworkError) {
        }
        try {
            $breaker->execute(static fn () => throw new LogDBNetworkError('x'));
        } catch (LogDBNetworkError) {
        }
        $this->assertSame(CircuitBreaker::STATE_OPEN, $breaker->getState());

        // Advance past durationOfBreak.
        $now += 6_000;
        $this->assertSame(CircuitBreaker::STATE_HALF_OPEN, $breaker->getState());

        // Successful probe → closed
        $breaker->execute(static fn () => 'ok');
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $breaker->getState());
    }

    public function testHalfOpenFailureReopensWithFreshTimer(): void
    {
        $now = 0.0;
        $breaker = new CircuitBreaker(
            failureThreshold: 0.5,
            samplingDurationMs: 10_000,
            durationOfBreakMs: 5_000,
            minimumThroughput: 2,
            clock: function () use (&$now): float {
                return $now;
            },
        );

        // Trip
        try {
            $breaker->execute(static fn () => throw new LogDBNetworkError('x'));
        } catch (LogDBNetworkError) {
        }
        try {
            $breaker->execute(static fn () => throw new LogDBNetworkError('x'));
        } catch (LogDBNetworkError) {
        }

        // Move into half-open and fail the probe.
        $now += 6_000;
        try {
            $breaker->execute(static fn () => throw new LogDBNetworkError('x'));
        } catch (LogDBNetworkError) {
        }

        $this->assertSame(CircuitBreaker::STATE_OPEN, $breaker->getState());

        // Still open one second later (fresh 5s timer).
        $now += 1_000;
        $this->assertSame(CircuitBreaker::STATE_OPEN, $breaker->getState());
    }
}
