<?php

declare(strict_types=1);

namespace Rasuvaeff\Resilience;

use Psr\Clock\ClockInterface;
use Rasuvaeff\Bulkhead\BulkheadFullException;
use Rasuvaeff\CircuitBreaker\CircuitOpenException;
use Rasuvaeff\CircuitBreaker\Clock\SystemClock;
use Rasuvaeff\CircuitBreaker\StorageFailure;
use Rasuvaeff\Duration\Duration;

/**
 * Tells "rejected without an attempt" apart from a downstream failure.
 *
 * A pipeline can end a call without running the callback at all: the circuit
 * is open (`CircuitOpenException`), every slot is taken
 * (`BulkheadFullException`), or the breaker's own store is down
 * (`StorageFailure`). None of them is a verdict about the dependency, so a
 * caller typically maps them to "503 + Retry-After" or a re-queue instead of
 * counting them against the downstream's health.
 *
 * `RetryExhausted` is never a rejection, even when its last attempt was
 * rejected: at least one earlier attempt may have reached the downstream.
 *
 * @api
 */
final readonly class Rejection
{
    private function __construct() {}

    public static function is(\Throwable $e): bool
    {
        return $e instanceof CircuitOpenException
            || $e instanceof BulkheadFullException
            || $e instanceof StorageFailure;
    }

    /**
     * How long to wait before trying again, when the rejecting layer knows.
     *
     * - `CircuitOpenException`: time left until the circuit half-opens,
     *   measured against `$clock` (never negative).
     * - `BulkheadFullException`: the slot lease — an upper bound on when a
     *   slot frees; `null` when the exception was built without one.
     * - `StorageFailure` and anything that is not a rejection: `null`.
     */
    public static function retryAfter(\Throwable $e, ?ClockInterface $clock = null): ?Duration
    {
        if ($e instanceof CircuitOpenException) {
            return $e->retryAfterIn(clock: $clock ?? new SystemClock());
        }

        if ($e instanceof BulkheadFullException) {
            return $e->retryAfter();
        }

        return null;
    }
}
