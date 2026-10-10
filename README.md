# rasuvaeff/resilience

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/resilience/v/stable.svg)](https://packagist.org/packages/rasuvaeff/resilience)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/resilience/downloads.svg)](https://packagist.org/packages/rasuvaeff/resilience)
[![Build](https://github.com/rasuvaeff/resilience/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/resilience/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/resilience/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/resilience/actions/workflows/static-analysis.yml)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/resilience/level.svg)](https://shepherd.dev/github/rasuvaeff/resilience)
[![License](https://poser.pugx.org/rasuvaeff/resilience/license.svg)](LICENSE.md)

Resilience pipeline for PHP: composes [`rasuvaeff/retry`](https://github.com/rasuvaeff/retry),
[`rasuvaeff/circuit-breaker`](https://github.com/rasuvaeff/circuit-breaker), and
[`rasuvaeff/bulkhead`](https://github.com/rasuvaeff/bulkhead) in the correct
order with the exception glue built in — the PHP analogue of Polly's
`ResiliencePipeline` / resilience4j's `Decorators`.

[Русская версия](README.ru.md)

> Using an AI coding assistant? Point it at [llms.txt](llms.txt) — a compact,
> self-contained API reference.

## Why

The three leaf packages compose with plain closures — but composing them
*correctly* requires glue that is easy to get wrong:

| Rule | Why |
|---|---|
| Retry must stop on `CircuitOpenException` | the circuit is open; more attempts are pointless and hostile to the downstream |
| Retry must stop on `BulkheadFullException` (by default) | saturation is not a transient error; blind re-attempts amplify overload |
| Retry must stop on the breaker's `StorageFailure` | an infrastructure outage is not a downstream verdict |
| Bulkhead innermost | a slot is held only while the callback runs — never during retry sleeps |
| Breaker outside the bulkhead | an open circuit rejects before a slot is even requested |
| Breaker inside vs outside retry | two legitimate orders with different semantics — see below |

`Pipeline` fixes the nesting order and appends the glue automatically,
without modifying the objects you pass in.

## Requirements

- PHP 8.3+
- `rasuvaeff/retry` ^1.3, `rasuvaeff/circuit-breaker` ^1.3,
  `rasuvaeff/bulkhead` ^1.3, `rasuvaeff/duration` ^1.1 (installed
  automatically)

## Installation

```bash
composer require rasuvaeff/resilience
```

## Usage

The block below is executable — `composer build` runs it against the real
API via [rasuvaeff/doc-exec](https://github.com/rasuvaeff/doc-exec), so the
`// =>` values cannot rot. It uses the in-memory backends; in production you
point the bulkhead and the breaker at Redis/APCu stores — the pipeline code
does not change.

```php doc-exec
use Rasuvaeff\Bulkhead\InMemoryBulkheadStore;
use Rasuvaeff\Bulkhead\SharedBulkhead;
use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitBreaker;
use Rasuvaeff\CircuitBreaker\CircuitOpenException;
use Rasuvaeff\CircuitBreaker\Clock\SystemClock;
use Rasuvaeff\CircuitBreaker\InMemoryStorage;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\Resilience\Pipeline;
use Rasuvaeff\Retry\Retry;

// Configure the leaves as usual - the pipeline takes them ready-made and
// knows nothing about stores, backends, or policies.
$pipeline = Pipeline::for('billing')
    ->bulkhead(new SharedBulkhead(
        name: 'billing',
        maxConcurrent: 2,
        store: new InMemoryBulkheadStore(),
        lease: Duration::seconds(5),
        maxWait: Duration::zero(),
    ))
    ->retry(
        Retry::new()
            ->maxAttempts(maxAttempts: 3)
            ->withImmediate(),
    )
    ->circuitBreaker(new CircuitBreaker(
        config: new BreakerConfig(
            name: 'billing',
            failureThreshold: Ratio::of(failures: 5, window: 10, within: Duration::seconds(60)),
            cooldown: Duration::seconds(30),
            successThreshold: 1,
            isFailure: static fn(\Throwable $e): bool => $e instanceof \RuntimeException,
        ),
        storage: new InMemoryStorage(),
        clock: new SystemClock(),
    ))
    ->build(); // immutable, reusable

// A transient failure is retried to success:
$attempt = 0;
$pipeline->call(function () use (&$attempt): string {
    if (++$attempt < 2) {
        throw new \RuntimeException('transient error');
    }

    return 'succeeded';
}); // => "succeeded"
$attempt; // => 2

// Optional fallback at the outermost level receives the terminal exception:
$pipeline->call(
    callback: static fn(): string => 'primary',
    fallback: static fn(\Throwable $e): string => $e instanceof CircuitOpenException ? 'degraded' : 'unexpected',
); // => "primary"
```

Every layer is optional, in any combination; an empty pipeline is a plain
call (convenient for conditional assembly).

### Nesting order

The default is `retry(circuitBreaker(bulkhead(callback)))`:

- **bulkhead innermost** — a slot is occupied only while the callback
  actually runs, re-requested per attempt; retry sleeps consume zero
  concurrency budget;
- **breaker around the bulkhead** — an open circuit rejects before a slot
  is requested;
- **retry outermost** — with the glue predicates appended.

### Breaker inside vs outside retry

| | `BreakerInsideRetry` (default) | `->breakerOutsideRetry()` |
|---|---|---|
| One breaker outcome per | attempt | logical operation (whole retry loop) |
| Open circuit mid-loop | cuts the remaining attempts instantly | cannot cut the loop short |
| Transient blip fixed by a retry | still counts toward the failure ratio | never reaches the failure ratio |
| Choose when | the breaker should see real per-call health | the breaker should judge operations, not attempts |
| `isFailure` receives | the callback's own exception (and, breaker-wrapping-bulkhead, `BulkheadFullException`) | what the retry loop throws — `RetryExhausted` on exhaustion (last downstream exception in its `lastException`), never the callback's exception directly |

### Exception glue

`build()` appends `stopIf` predicates to a **copy** of your retry builder
(`Retry` is immutable — the instance you pass is never modified, and your
own `stopIf`/`retryIf` rules are preserved):

- `CircuitOpenException` → stop, always;
- `StorageFailure` (breaker's storage outage) → stop, always;
- `BulkheadFullException` → stop by default; opt back in with
  `->retryOnBulkheadFull()` when a slot is expected to free within the
  backoff window.

All terminal exceptions surface **unchanged** — `RetryExhausted`,
`CircuitOpenException`, `BulkheadFullException`, `StorageFailure`, or the
callback's own exception. The optional `fallback` receives whichever of
them ended the call.

### Rejections and domain exceptions

A call can end without the callback ever running: the circuit is open, every
slot is taken, or the breaker's store failed before the callback ran.
`Rejection::is()` recognises exactly these three, and `Rejection::retryAfter()` turns the leaf's hint into
a relative `Duration` for a `Retry-After` header or a re-queue delay.
`onRejected()` maps them to your domain exception once per pipeline, instead
of a `try/catch` around every call site:

```php doc-exec
use Rasuvaeff\Bulkhead\BulkheadFullException;
use Rasuvaeff\Bulkhead\InMemoryBulkheadStore;
use Rasuvaeff\Bulkhead\SharedBulkhead;
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\Resilience\Pipeline;
use Rasuvaeff\Resilience\Rejection;

final class BillingUnavailable extends \RuntimeException {}

$store = new InMemoryBulkheadStore();
$store->tryAcquire('billing', 1, Duration::seconds(5)); // the only slot is busy

$pipeline = Pipeline::for('billing')
    ->bulkhead(new SharedBulkhead(
        name: 'billing',
        maxConcurrent: 1,
        store: $store,
        lease: Duration::seconds(5),
        maxWait: Duration::zero(),
    ))
    ->onRejected(static fn(\Throwable $e): \Throwable => new BillingUnavailable('billing is busy', previous: $e))
    ->build();

try {
    $pipeline->call(static fn(): string => 'charged');
} catch (BillingUnavailable $e) {
    $e->getMessage(); // => "billing is busy"
    Rejection::is($e->getPrevious()); // => true
    Rejection::retryAfter($e->getPrevious())?->toMillis(); // => 5000
}

Rejection::is(new \RuntimeException('HTTP 500')); // => false
```

- The mapper runs at the outermost level, before `fallback`; the fallback
  receives the mapped exception. Any other exception passes through
  untouched.
- A `StorageFailure` counts only when the store failed on `admit` or on
  the rejected-path `snapshot`. One raised by `recordOutcome` means the
  callback already ran and its result was not recorded: not a rejection,
  because re-queueing it could execute a non-idempotent call twice.
- `RetryExhausted` is never a rejection, even when its last attempt was:
  an earlier attempt may have reached the downstream.
- `retryAfter()` is the time left until the circuit half-opens for
  `CircuitOpenException` (measured against the clock you pass, system clock
  by default) and the slot lease, an upper bound, for
  `BulkheadFullException`. It is `null` for `StorageFailure`.

`call()` is generic: Psalm infers `string` above from the callback, no
`@var` at the call site.

### Public API

| Type | Description |
|---|---|
| `Pipeline` | Builder: `for(name)`, `bulkhead(Bulkhead)`, `retry(Retry)`, `circuitBreaker(CircuitBreakerInterface)`, `onRejected(Closure)`, `breakerOutsideRetry()`, `retryOnBulkheadFull()`, `build()` |
| `CompiledPipeline` | Immutable result: generic `call(callable(): T, ?callable(Throwable): T $fallback): T`, `name()` |
| `Rejection` | `is(Throwable): bool`, `retryAfter(Throwable, ?ClockInterface): ?Duration` |
| `PipelineOrder` | Enum: `BreakerInsideRetry`, `BreakerOutsideRetry` |

## Security

- The pipeline adds no I/O, no storage, and no exception types of its own;
  the security posture is that of the leaf packages (see their READMEs —
  notably the bulkhead's fail-closed store semantics and the breaker's
  `StorageFailure` contract).
- With the breaker wrapping the bulkhead, a `BulkheadFullException`
  passes through the breaker's `isFailure` classifier. **Classify it as
  not-a-failure** (`Ignored`) unless you deliberately want local saturation
  to open the circuit against a healthy downstream:

```php
isFailure: static fn(\Throwable $e): bool =>
    !$e instanceof \Rasuvaeff\Bulkhead\BulkheadFullException
    && $e instanceof \Psr\Http\Client\ClientExceptionInterface,
```

## Examples

See [`examples/`](examples/) — runnable scripts using the in-memory
backends of the leaf packages (no server needed).

## Development

```bash
make install
make build      # validate → normalize → require-checker → cs → psalm → test
make release-check
```

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
