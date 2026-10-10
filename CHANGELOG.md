# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.2.0 — 2026-10-10

- `Pipeline::onRejected(\Closure(\Throwable): \Throwable)`: map a rejection (`CircuitOpenException`, `BulkheadFullException`, the breaker's `StorageFailure`) to a domain exception once per pipeline; runs outermost, before `fallback`; other exceptions pass through untouched (#3).
- `CompiledPipeline::call()` is generic (`@template T`): Psalm infers the result type from the callback, no `@var` at call sites (#3).
- `Pipeline::bulkhead()` accepts any `Rasuvaeff\Bulkhead\Bulkhead` and `Pipeline::circuitBreaker()` any `Rasuvaeff\CircuitBreaker\CircuitBreakerInterface`, so decorators and test stubs can replace the leaves (#4).
- New `Rejection::is()` / `Rejection::retryAfter()`: tell "rejected without an attempt" from a downstream failure and get a relative retry hint; `RetryExhausted` is never a rejection (#5).
- Requires `rasuvaeff/retry` ^1.3, `rasuvaeff/circuit-breaker` ^1.3, `rasuvaeff/bulkhead` ^1.3, and `psr/clock` ^1.0.

## 0.1.0 — 2026-08-21

- Initial release: `Pipeline` builder → immutable `CompiledPipeline` composing `rasuvaeff/retry`, `rasuvaeff/circuit-breaker`, and `rasuvaeff/bulkhead` with the correct nesting order (`retry(circuitBreaker(bulkhead(callback)))` — bulkhead innermost, slot held only while the callback runs) and the exception glue built in (retry stops on `CircuitOpenException`, the breaker's `StorageFailure`, and — by default — `BulkheadFullException`).
- `->breakerOutsideRetry()`: the whole retry loop as one breaker outcome (documented trade-off vs the per-attempt default).
- `->retryOnBulkheadFull()`: opt back into retrying after saturation when a slot should free within the backoff window.
- Optional outermost `fallback` receiving the terminal exception of whichever layer gave up; terminal exceptions of the leaves surface unchanged.
- Every layer optional; an empty pipeline is a plain call.
