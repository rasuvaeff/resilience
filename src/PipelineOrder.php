<?php

declare(strict_types=1);

namespace Rasuvaeff\Resilience;

/**
 * How the retry and circuit-breaker layers nest when both are present.
 *
 * The two orders are both legitimate and mean different things — pick by
 * what one "outcome" should be for the breaker's failure ratio:
 *
 * - `BreakerInsideRetry` (the default): every attempt asks the breaker
 *   first. Each attempt is one breaker outcome, an open circuit fails the
 *   remaining attempts instantly (the glue stops retrying on
 *   `CircuitOpenException`), and the breaker's window counts attempts.
 * - `BreakerOutsideRetry`: the whole retry loop is one breaker call. The
 *   breaker counts one outcome per *logical operation* (a transient blip
 *   fixed by a retry never reaches the failure ratio), but while retrying,
 *   the breaker cannot cut the loop short. Note for the breaker's
 *   `isFailure` classifier: in this order it receives what the retry loop
 *   throws — `RetryExhausted` on exhaustion (with the last downstream
 *   exception in its `lastException`), never the callback's own exception
 *   directly and never `BulkheadFullException` (the glue stops the loop on
 *   it, and `RetryExhausted` is what reaches the breaker).
 *
 * @api
 */
enum PipelineOrder
{
    case BreakerInsideRetry;

    case BreakerOutsideRetry;
}
