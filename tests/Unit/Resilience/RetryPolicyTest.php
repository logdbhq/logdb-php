<?php

declare(strict_types=1);

namespace LogDB\Tests\Unit\Resilience;

use LogDB\Errors\LogDBAuthError;
use LogDB\Errors\LogDBConfigError;
use LogDB\Errors\LogDBNetworkError;
use LogDB\Errors\LogDBTimeoutError;
use LogDB\Resilience\RetryPolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RetryPolicyTest extends TestCase
{
    public function testReturnsResultOnFirstSuccess(): void
    {
        $calls = 0;
        $result = RetryPolicy::execute(
            function () use (&$calls): string {
                $calls++;
                return 'ok';
            },
            maxRetries: 3,
            retryDelay: 1,
            retryBackoffMultiplier: 2.0,
            sleeper: static fn (int $ms) => null,
        );
        $this->assertSame('ok', $result);
        $this->assertSame(1, $calls);
    }

    public function testRetriesOnNetworkError(): void
    {
        $calls = 0;
        $result = RetryPolicy::execute(
            function () use (&$calls): string {
                $calls++;
                if ($calls < 3) {
                    throw new LogDBNetworkError('boom');
                }
                return 'ok';
            },
            maxRetries: 3,
            retryDelay: 1,
            retryBackoffMultiplier: 2.0,
            random: static fn () => 0.5,
            sleeper: static fn (int $ms) => null,
        );
        $this->assertSame('ok', $result);
        $this->assertSame(3, $calls);
    }

    public function testRetriesOnTimeoutError(): void
    {
        $calls = 0;
        try {
            RetryPolicy::execute(
                function () use (&$calls): void {
                    $calls++;
                    throw new LogDBTimeoutError();
                },
                maxRetries: 2,
                retryDelay: 1,
                retryBackoffMultiplier: 2.0,
                sleeper: static fn (int $ms) => null,
            );
            $this->fail('expected timeout error');
        } catch (LogDBTimeoutError) {
            $this->assertSame(3, $calls); // initial + 2 retries
        }
    }

    public function testAuthErrorIsNotRetried(): void
    {
        $calls = 0;
        $this->expectException(LogDBAuthError::class);
        try {
            RetryPolicy::execute(
                function () use (&$calls): void {
                    $calls++;
                    throw new LogDBAuthError();
                },
                maxRetries: 5,
                retryDelay: 1,
                retryBackoffMultiplier: 2.0,
                sleeper: static fn (int $ms) => null,
            );
        } finally {
            $this->assertSame(1, $calls);
        }
    }

    public function testConfigErrorIsNotRetried(): void
    {
        $calls = 0;
        $this->expectException(LogDBConfigError::class);
        try {
            RetryPolicy::execute(
                function () use (&$calls): void {
                    $calls++;
                    throw new LogDBConfigError('bad');
                },
                maxRetries: 5,
                retryDelay: 1,
                retryBackoffMultiplier: 2.0,
                sleeper: static fn (int $ms) => null,
            );
        } finally {
            $this->assertSame(1, $calls);
        }
    }

    public function testGenericThrowableIsWrappedAsNetworkError(): void
    {
        $this->expectException(LogDBNetworkError::class);
        RetryPolicy::execute(
            static fn () => throw new RuntimeException('something else'),
            maxRetries: 1,
            retryDelay: 1,
            retryBackoffMultiplier: 2.0,
            sleeper: static fn (int $ms) => null,
        );
    }
}
