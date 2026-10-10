<?php

declare(strict_types=1);

namespace Rasuvaeff\Resilience;

use Rasuvaeff\Bulkhead\Bulkhead;
use Rasuvaeff\CircuitBreaker\CircuitBreakerInterface;
use Rasuvaeff\Retry\Retry;

/**
 * Builder for a {@see CompiledPipeline}: pick the layers, get the correct
 * nesting order and the exception glue for free.
 *
 * The default nesting is `retry(circuitBreaker(bulkhead(callback)))`:
 *
 * - the bulkhead is innermost, so a slot is occupied only while the callback
 *   actually runs — never during retry sleeps, and never when the breaker
 *   rejects;
 * - the breaker wraps the bulkhead, so an open circuit rejects before a slot
 *   is even requested;
 * - the retry is outermost and stops immediately on `CircuitOpenException`,
 *   `BulkheadFullException` (opt back in via {@see retryOnBulkheadFull()}),
 *   and the breaker's `StorageFailure` — see {@see CompiledPipeline}.
 *
 * Every layer is optional; an empty pipeline is a plain call. The leaf
 * objects are configured by you and passed in ready-made — this builder
 * knows nothing about stores, backends, or policies beyond how to nest
 * them.
 *
 * @api
 */
final readonly class Pipeline
{
    /**
     * @param non-empty-string $name
     * @param (\Closure(\Throwable): \Throwable)|null $onRejected
     */
    private function __construct(
        private string $name,
        private ?Bulkhead $bulkhead,
        private ?Retry $retry,
        private ?CircuitBreakerInterface $circuitBreaker,
        private PipelineOrder $order,
        private bool $retryOnBulkheadFull,
        private ?\Closure $onRejected,
    ) {}

    /**
     * @param string $name identifies this pipeline in exceptions and
     *                     observability; not a storage key; must be non-empty
     */
    public static function for(string $name): self
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Pipeline name cannot be empty');
        }

        return new self(
            name: $name,
            bulkhead: null,
            retry: null,
            circuitBreaker: null,
            order: PipelineOrder::BreakerInsideRetry,
            retryOnBulkheadFull: false,
            onRejected: null,
        );
    }

    /**
     * The innermost layer: a slot is occupied only while the callback
     * actually runs, re-requested per retry attempt. Any {@see Bulkhead}
     * implementation is accepted — `SharedBulkhead`, a decorator, a stub.
     */
    public function bulkhead(Bulkhead $bulkhead): self
    {
        return $this->with(bulkhead: $bulkhead);
    }

    /**
     * The retry builder is taken as configured; {@see build()} appends the
     * glue predicates on a copy (`Retry` is immutable), so the instance you
     * pass is never modified and your own `stopIf`/`retryIf` rules are kept.
     */
    public function retry(Retry $retry): self
    {
        return $this->with(retry: $retry);
    }

    /**
     * Any {@see CircuitBreakerInterface} implementation is accepted —
     * `CircuitBreaker`, a decorator, a stub.
     */
    public function circuitBreaker(CircuitBreakerInterface $circuitBreaker): self
    {
        return $this->with(circuitBreaker: $circuitBreaker);
    }

    /**
     * Maps a rejection — `CircuitOpenException`, `BulkheadFullException`, or
     * the breaker's `StorageFailure` ({@see Rejection::is()}) — to an
     * exception of your domain, once per pipeline instead of a `try/catch`
     * per call site. Runs at the outermost level, before `fallback`; other
     * exceptions pass through untouched. Keep the rejection as `previous`
     * of the exception you return, so the cause stays in the trace.
     *
     * @param \Closure(\Throwable): \Throwable $mapper
     */
    public function onRejected(\Closure $mapper): self
    {
        return $this->with(onRejected: $mapper);
    }

    /**
     * Deliberate deviation from the default nesting: the whole retry loop
     * becomes one breaker call. See {@see PipelineOrder} for the semantics
     * trade-off. No effect unless both layers are present.
     */
    public function breakerOutsideRetry(): self
    {
        return $this->with(order: PipelineOrder::BreakerOutsideRetry);
    }

    /**
     * Let retry re-attempt after `BulkheadFullException` (default: stop).
     * Legitimate when a slot is expected to free within the backoff window —
     * the bulkhead sits inside the retry, so the slot is re-requested per
     * attempt. Keep the default when saturation means sustained overload.
     */
    public function retryOnBulkheadFull(): self
    {
        return $this->with(retryOnBulkheadFull: true);
    }

    public function build(): CompiledPipeline
    {
        return new CompiledPipeline(
            name: $this->name,
            bulkhead: $this->bulkhead,
            retry: $this->retry instanceof Retry
                ? CompiledPipeline::glued($this->retry, retryOnBulkheadFull: $this->retryOnBulkheadFull)
                : null,
            circuitBreaker: $this->circuitBreaker,
            order: $this->order,
            onRejected: $this->onRejected,
        );
    }

    /**
     * @param (\Closure(\Throwable): \Throwable)|null $onRejected
     */
    private function with(
        ?Bulkhead $bulkhead = null,
        ?Retry $retry = null,
        ?CircuitBreakerInterface $circuitBreaker = null,
        ?PipelineOrder $order = null,
        ?bool $retryOnBulkheadFull = null,
        ?\Closure $onRejected = null,
    ): self {
        return new self(
            name: $this->name,
            bulkhead: $bulkhead ?? $this->bulkhead,
            retry: $retry ?? $this->retry,
            circuitBreaker: $circuitBreaker ?? $this->circuitBreaker,
            order: $order ?? $this->order,
            retryOnBulkheadFull: $retryOnBulkheadFull ?? $this->retryOnBulkheadFull,
            onRejected: $onRejected ?? $this->onRejected,
        );
    }
}
