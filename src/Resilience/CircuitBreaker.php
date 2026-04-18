<?php

declare(strict_types=1);

namespace LogDB\Resilience;

use Closure;
use LogDB\Errors\LogDBCircuitOpenError;
use Throwable;

/**
 * Failure-rate sliding-window circuit breaker. Mirrors Polly's
 * `AdvancedCircuitBreakerAsync`:
 *   - closed → records outcomes; trips to open when failure rate exceeds threshold
 *   - open → rejects fast with LogDBCircuitOpenError until durationOfBreak elapses
 *   - halfOpen → allows ONE probe call; success closes, failure re-opens
 *
 * Note on PHP request-scoped lifetime: the breaker's state is in-memory and lives
 * only for the duration of a single PHP request / CLI process. Use a long-lived
 * worker (Octane, RoadRunner, queue worker) to benefit from cross-request state.
 */
final class CircuitBreaker
{
    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_OPEN = 'halfOpen';

    private string $state = self::STATE_CLOSED;
    /** @var list<array{ts: float, ok: bool}> */
    private array $outcomes = [];
    private float $openedAt = 0.0;
    private bool $halfOpenInFlight = false;

    private readonly Closure $clock;

    public function __construct(
        public readonly float $failureThreshold,
        public readonly int $samplingDurationMs,
        public readonly int $durationOfBreakMs,
        public readonly int $minimumThroughput = 5,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true) * 1000.0;
    }

    public function getState(): string
    {
        $this->refreshState();
        return $this->state;
    }

    /**
     * @template T
     * @param Closure(): T $fn
     * @return T
     */
    public function execute(Closure $fn): mixed
    {
        $this->refreshState();

        if ($this->state === self::STATE_OPEN) {
            throw new LogDBCircuitOpenError();
        }

        if ($this->state === self::STATE_HALF_OPEN) {
            if ($this->halfOpenInFlight) {
                throw new LogDBCircuitOpenError();
            }
            $this->halfOpenInFlight = true;
        }

        try {
            $result = $fn();
            $this->recordSuccess();
            return $result;
        } catch (Throwable $e) {
            $this->recordFailure();
            throw $e;
        } finally {
            if ($this->state === self::STATE_HALF_OPEN) {
                $this->halfOpenInFlight = false;
            }
        }
    }

    private function recordSuccess(): void
    {
        if ($this->state === self::STATE_HALF_OPEN) {
            $this->state = self::STATE_CLOSED;
            $this->outcomes = [];
            return;
        }
        $this->outcomes[] = ['ts' => ($this->clock)(), 'ok' => true];
        $this->evict();
    }

    private function recordFailure(): void
    {
        $now = ($this->clock)();
        if ($this->state === self::STATE_HALF_OPEN) {
            $this->state = self::STATE_OPEN;
            $this->openedAt = $now;
            $this->outcomes = [];
            return;
        }
        $this->outcomes[] = ['ts' => $now, 'ok' => false];
        $this->evict();
        $this->maybeTrip();
    }

    private function maybeTrip(): void
    {
        if (count($this->outcomes) < $this->minimumThroughput) {
            return;
        }
        $failures = 0;
        foreach ($this->outcomes as $o) {
            if (!$o['ok']) {
                $failures++;
            }
        }
        $rate = $failures / count($this->outcomes);
        if ($rate >= $this->failureThreshold) {
            $this->state = self::STATE_OPEN;
            $this->openedAt = ($this->clock)();
            $this->outcomes = [];
        }
    }

    private function refreshState(): void
    {
        if ($this->state === self::STATE_OPEN) {
            $elapsed = ($this->clock)() - $this->openedAt;
            if ($elapsed >= $this->durationOfBreakMs) {
                $this->state = self::STATE_HALF_OPEN;
                $this->halfOpenInFlight = false;
            }
        }
        $this->evict();
    }

    private function evict(): void
    {
        $cutoff = ($this->clock)() - $this->samplingDurationMs;
        while ($this->outcomes !== [] && $this->outcomes[0]['ts'] < $cutoff) {
            array_shift($this->outcomes);
        }
    }
}
