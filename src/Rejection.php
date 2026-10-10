<?php

declare(strict_types=1);

namespace Rasuvaeff\Resilience;

use Psr\Clock\ClockInterface;
use Rasuvaeff\Bulkhead\BulkheadFullException;
use Rasuvaeff\CircuitBreaker\CircuitOpenException;
use Rasuvaeff\CircuitBreaker\Clock\SystemClock;
use Rasuvaeff\CircuitBreaker\StorageFailure;
use Rasuvaeff\CircuitBreaker\StorageOperation;
use Rasuvaeff\Duration\Duration;

/**
 * Tells "rejected without an attempt" apart from a downstream failure.
 *
 * A pipeline can end a call without running the callback at all: the circuit
 * is open (`CircuitOpenException`), every slot is taken
 * (`BulkheadFullException`), or the breaker's own store failed before the
 * callback ran (`StorageFailure` from `admit` or `snapshot`). None of them is
 * a verdict about the dependency, so a
 * caller typically maps them to "503 + Retry-After" or a re-queue instead of
 * counting them against the downstream's health.
 *
 * Not rejections, because the downstream may already have been hit:
 *
 * - `StorageFailure` from `recordOutcome` — the store failed while recording
 *   the outcome of a callback that already ran;
 * - `RetryExhausted`, even when its last attempt was rejected — an earlier
 *   attempt may have reached the downstream.
 *
 * @api
 */
final readonly class Rejection
{
    /**
     * Inside `CircuitBreaker::call()`, `snapshot` is read only on the
     * rejected-admission path, so both happen before the callback.
     */
    private const array PRE_CALL_STORAGE_OPERATIONS = [
        StorageOperation::Admit->value,
        StorageOperation::Snapshot->value,
    ];

    private function __construct() {}

    public static function is(\Throwable $e): bool
    {
        if ($e instanceof CircuitOpenException || $e instanceof BulkheadFullException) {
            return true;
        }

        return $e instanceof StorageFailure
            && \in_array($e->operation, self::PRE_CALL_STORAGE_OPERATIONS, strict: true);
    }

    /**
     * How long to wait before trying again, when the rejecting layer knows.
     *
     * - `CircuitOpenException`: time left until the circuit half-opens,
     *   measured against `$clock` (never negative).
     * - `BulkheadFullException`: the slot lease — an upper bound on when a
     *   slot frees; `null` when the exception was built without one.
     * - a pre-call `StorageFailure` and anything that is not a rejection:
     *   `null`.
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
