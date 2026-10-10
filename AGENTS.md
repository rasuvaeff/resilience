# AGENTS.md — resilience

Guidance for AI agents working on this package. Read before changing code.

## What this is

`rasuvaeff/resilience` (namespace `Rasuvaeff\Resilience`) is a thin
composition layer over `rasuvaeff/retry`, `rasuvaeff/circuit-breaker`, and
`rasuvaeff/bulkhead`: `Pipeline` (builder) → `CompiledPipeline`
(immutable, reusable, generic `call(callable, ?callable $fallback): T`),
plus `Rejection` (classifies "rejected without an attempt"). It
encodes the correct nesting order and the exception glue; it deliberately
does NOT re-export or configure the leaves — they are passed in ready-made.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **The nesting order and the glue ARE the product — never weaken either.**
   Default nesting is `retry(circuitBreaker(bulkhead(callback)))`: bulkhead
   innermost (slot only while the callback runs, free during retry sleeps),
   breaker around it (open circuit rejects before a slot is requested),
   retry outermost. The glue stops retries on `CircuitOpenException` and
   `StorageFailure` unconditionally, and on `BulkheadFullException` unless
   `retryOnBulkheadFull()` opted in. Any change here changes the package's
   core promise — it is a major, and both README order tables must move in
   the same commit.
4. **Preserve the public contract.** Update README + README.ru.md +
   llms.txt + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make: `make build`, `make cs-fix`, `make psalm`, `make test`,
`make test-coverage`, `make mutation`, `make release-check`.

All four dependencies (retry, circuit-breaker, bulkhead, duration) are normal Packagist packages — no path-repo, no
monorepo-root mount needed. `composer.lock` is gitignored (library).

## Invariants & gotchas

- **The glue works because `Retry` is immutable**: `build()` calls
  `stopIf()` on a copy, so the user's builder instance is never modified
  and their own predicates are preserved (`stopIf` accumulates and always
  wins over `retryIf`/`retryOn`). If retry ever became mutable, `glued()`
  would silently corrupt user state — `buildDoesNotMutateTheUsersRetryBuilder`
  pins this.
- **No pipeline-owned exceptions on purpose**: terminal exceptions of the
  leaves surface unchanged so `catch` sites written against the leaf
  packages keep working. Do not add wrapper exceptions. `onRejected()` is
  the one exception: an opt-in, user-supplied mapper that touches only
  `Rejection::is()` exceptions — the pipeline still owns no exception type.
- **The breaker's `isFailure` sees `BulkheadFullException`** (breaker wraps
  bulkhead). The README documents classifying it as not-a-failure; the
  pipeline cannot do it for the user (`isFailure` is required and entirely
  theirs).
- `fallback` is outermost and catches `\Throwable` — including the
  callback's own exception when no layer is configured. That is documented
  behavior, not an accident.
- Tests use the real in-memory implementations of the leaves
  (`InMemoryStorage`, `InMemoryBulkheadStore`, `FakeClock`, `FakeSleeper`) —
  not mocks. No `tests/Integration/`: the pipeline does no I/O; the leaves'
  own suites cover their backends.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.
- `examples/` is part of the public contract: keep scripts runnable
  (in-memory backends, no server) and update `examples/README.md` when
  example usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment. Never
  revert to floating `@vN` tags; updates go through Dependabot. Workflows
  carry `permissions: { contents: read }` and `persist-credentials: false`
  on every checkout. Verify with `zizmor --persona=auditor .github/`.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit;
  and `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build`; if the change affects public API or release
  safety, also run `make release-check`. Paste the output.
