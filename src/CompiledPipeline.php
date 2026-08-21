<?php

declare(strict_types=1);

namespace Rasuvaeff\Resilience;

use Rasuvaeff\Bulkhead\BulkheadFullException;
use Rasuvaeff\Bulkhead\SharedBulkhead;
use Rasuvaeff\CircuitBreaker\CircuitBreaker;
use Rasuvaeff\CircuitBreaker\CircuitOpenException;
use Rasuvaeff\CircuitBreaker\StorageFailure;
use Rasuvaeff\Retry\Retry;

/**
 * An immutable, reusable composition of resilience layers, produced by
 * {@see Pipeline::build()}.
 *
 * The layers keep their own exception types — `RetryExhausted`,
 * `CircuitOpenException`, `BulkheadFullException`, `StorageFailure` all
 * surface unchanged; this class only routes them correctly between layers:
 *
 * - retry never re-attempts on `CircuitOpenException` (the circuit is open —
 *   more attempts are pointless and hostile to the downstream),
 * - not on `BulkheadFullException` by default (overload is not a transient
 *   error — blind re-attempts amplify it; opt in via
 *   {@see Pipeline::retryOnBulkheadFull()} when a slot should free within
 *   the backoff window),
 * - never on the breaker's `StorageFailure` (an infrastructure outage is not
 *   a downstream verdict).
 *
 * @api
 */
final readonly class CompiledPipeline
{
    /**
     * @param non-empty-string $name
     *
     * @internal construct via {@see Pipeline::build()}
     */
    public function __construct(
        private string $name,
        private ?SharedBulkhead $bulkhead,
        private ?Retry $retry,
        private ?CircuitBreaker $circuitBreaker,
        private PipelineOrder $order,
    ) {}

    /**
     * Appends the glue predicates to a retry builder. `Retry` is immutable,
     * so the caller's instance is untouched and user-added predicates are
     * preserved (`stopIf` accumulates).
     *
     * `BulkheadFullException` stops retries by default — saturation is not a
     * transient error, and blind re-attempts amplify the overload. Opting in
     * via {@see Pipeline::retryOnBulkheadFull()} is legitimate when a slot
     * is expected to free within the backoff window (the bulkhead sits
     * inside the retry, so the slot is re-requested per attempt).
     *
     * @internal used by {@see Pipeline::build()}
     */
    public static function glued(Retry $retry, bool $retryOnBulkheadFull): Retry
    {
        $retry = $retry
            ->stopIf(predicate: static fn(\Throwable $e): bool => $e instanceof CircuitOpenException)
            ->stopIf(predicate: static fn(\Throwable $e): bool => $e instanceof StorageFailure);

        if (!$retryOnBulkheadFull) {
            $retry = $retry->stopIf(predicate: static fn(\Throwable $e): bool => $e instanceof BulkheadFullException);
        }

        return $retry;
    }

    /** @return non-empty-string */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Runs the callback through every configured layer. With none configured
     * this is a plain call — valid, and convenient for conditional assembly.
     *
     * `$fallback` is applied at the outermost level and receives the terminal
     * exception of whichever layer gave up: `RetryExhausted`,
     * `CircuitOpenException`, `BulkheadFullException`, `StorageFailure`, or
     * the callback's own exception when no layer handles it.
     *
     * @param callable(): mixed $callback
     * @param (callable(\Throwable): mixed)|null $fallback
     */
    public function call(callable $callback, ?callable $fallback = null): mixed
    {
        $operation = $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback);

        // Innermost: a slot is occupied only while the callback actually
        // runs — never while retry sleeps between attempts, so waiting
        // workers don't starve the downstream's concurrency budget.
        $bulkhead = $this->bulkhead;
        if ($bulkhead instanceof SharedBulkhead) {
            $inner = $operation;
            $operation = static fn(): mixed => $bulkhead->call(callback: $inner);
        }

        // The breaker wraps the bulkhead: an open circuit rejects before a
        // slot is even requested.
        $circuitBreaker = $this->circuitBreaker;
        $retry = $this->retry;

        if ($circuitBreaker instanceof CircuitBreaker && $this->order === PipelineOrder::BreakerInsideRetry) {
            $inner = $operation;
            $operation = static fn(): mixed => $circuitBreaker->call(callback: $inner);
        }

        if ($retry instanceof Retry) {
            $inner = $operation;
            $operation = static fn(): mixed => $retry->run(operation: $inner);
        }

        if ($circuitBreaker instanceof CircuitBreaker && $this->order === PipelineOrder::BreakerOutsideRetry) {
            $inner = $operation;
            $operation = static fn(): mixed => $circuitBreaker->call(callback: $inner);
        }

        if ($fallback === null) {
            return $operation();
        }

        try {
            return $operation();
        } catch (\Throwable $e) {
            return $fallback($e);
        }
    }
}
