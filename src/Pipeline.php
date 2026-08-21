<?php

declare(strict_types=1);

namespace Rasuvaeff\Resilience;

use Rasuvaeff\Bulkhead\SharedBulkhead;
use Rasuvaeff\CircuitBreaker\CircuitBreaker;
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
     */
    private function __construct(
        private string $name,
        private ?SharedBulkhead $bulkhead,
        private ?Retry $retry,
        private ?CircuitBreaker $circuitBreaker,
        private PipelineOrder $order,
        private bool $retryOnBulkheadFull,
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
        );
    }

    /**
     * The innermost layer: a slot is occupied only while the callback
     * actually runs, re-requested per retry attempt.
     */
    public function bulkhead(SharedBulkhead $bulkhead): self
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

    public function circuitBreaker(CircuitBreaker $circuitBreaker): self
    {
        return $this->with(circuitBreaker: $circuitBreaker);
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
            retry: $this->retry === null
                ? null
                : CompiledPipeline::glued($this->retry, retryOnBulkheadFull: $this->retryOnBulkheadFull),
            circuitBreaker: $this->circuitBreaker,
            order: $this->order,
        );
    }

    private function with(
        ?SharedBulkhead $bulkhead = null,
        ?Retry $retry = null,
        ?CircuitBreaker $circuitBreaker = null,
        ?PipelineOrder $order = null,
        ?bool $retryOnBulkheadFull = null,
    ): self {
        return new self(
            name: $this->name,
            bulkhead: $bulkhead ?? $this->bulkhead,
            retry: $retry ?? $this->retry,
            circuitBreaker: $circuitBreaker ?? $this->circuitBreaker,
            order: $order ?? $this->order,
            retryOnBulkheadFull: $retryOnBulkheadFull ?? $this->retryOnBulkheadFull,
        );
    }
}
