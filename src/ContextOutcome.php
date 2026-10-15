<?php

declare(strict_types=1);

namespace Rasuvaeff\Resilience;

use Rasuvaeff\Context\ContextException;

/**
 * Breaker classification for calls that run under a rasuvaeff/context budget.
 *
 * The breaker's `isFailure` is yours (`BreakerConfig`), so the pipeline
 * cannot change it. A context exception describes the *caller* — its budget
 * ran out or it was cancelled — not the dependency; counted as a failure, one
 * client with a short budget opens the circuit for everyone. And
 * `ContextException` extends `RuntimeException`, so a broad classifier
 * counts it without anyone noticing. Wrap your classifier:
 *
 *     isFailure: ContextOutcome::ignoring(static fn (\Throwable $e): bool => $e instanceof ClientExceptionInterface)
 *
 * Never turn a context exception into a success instead: a half-open probe
 * that ran out of the caller's budget would close the circuit.
 *
 * @api
 */
final readonly class ContextOutcome
{
    private function __construct() {}

    /**
     * @param callable(\Throwable): bool $isFailure
     *
     * @return \Closure(\Throwable): bool `false` for any `ContextException`, `$isFailure($e)` otherwise
     */
    public static function ignoring(callable $isFailure): \Closure
    {
        return static fn(\Throwable $e): bool => !$e instanceof ContextException && $isFailure($e);
    }
}
