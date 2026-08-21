# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.1.0 — 2026-08-21

- Initial release: `Pipeline` builder → immutable `CompiledPipeline` composing `rasuvaeff/retry`, `rasuvaeff/circuit-breaker`, and `rasuvaeff/bulkhead` with the correct nesting order (`retry(circuitBreaker(bulkhead(callback)))` — bulkhead innermost, slot held only while the callback runs) and the exception glue built in (retry stops on `CircuitOpenException`, the breaker's `StorageFailure`, and — by default — `BulkheadFullException`).
- `->breakerOutsideRetry()`: the whole retry loop as one breaker outcome (documented trade-off vs the per-attempt default).
- `->retryOnBulkheadFull()`: opt back into retrying after saturation when a slot should free within the backoff window.
- Optional outermost `fallback` receiving the terminal exception of whichever layer gave up; terminal exceptions of the leaves surface unchanged.
- Every layer optional; an empty pipeline is a plain call.
