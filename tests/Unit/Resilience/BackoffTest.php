<?php

declare(strict_types=1);

namespace LogDB\Tests\Unit\Resilience;

use LogDB\Resilience\Backoff;
use PHPUnit\Framework\TestCase;

final class BackoffTest extends TestCase
{
    public function testDoublesPerAttemptWithJitterRange(): void
    {
        $rand = static fn (): float => 0.0;     // jitter floor → multiplier 0.8
        $a0 = Backoff::compute(0, 1_000, 2.0, $rand);
        $a1 = Backoff::compute(1, 1_000, 2.0, $rand);
        $a2 = Backoff::compute(2, 1_000, 2.0, $rand);

        $this->assertSame(800, $a0);
        $this->assertSame(1_600, $a1);
        $this->assertSame(3_200, $a2);
    }

    public function testJitterCeilingReachesPlus20Percent(): void
    {
        $rand = static fn (): float => 0.999;   // close to 1 → multiplier ≈ 1.2
        $value = Backoff::compute(0, 1_000, 2.0, $rand);
        $this->assertGreaterThan(1_180, $value);
        $this->assertLessThanOrEqual(1_200, $value);
    }
}
