<?php

declare(strict_types=1);

use Rasuvaeff\Bulkhead\InMemoryBulkheadStore;
use Rasuvaeff\Bulkhead\SharedBulkhead;
use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitBreaker;
use Rasuvaeff\CircuitBreaker\Clock\SystemClock;
use Rasuvaeff\CircuitBreaker\InMemoryStorage;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\Resilience\Pipeline;
use Rasuvaeff\Retry\Retry;

require dirname(__DIR__) . '/vendor/autoload.php';

// All three layers on in-memory backends: no server needed. In production
// you would point the bulkhead and the breaker at Redis/APCu stores - the
// pipeline code does not change.
$pipeline = Pipeline::for('flaky-service')
    ->bulkhead(new SharedBulkhead(
        name: 'flaky-service',
        maxConcurrent: 2,
        store: new InMemoryBulkheadStore(),
        lease: Duration::seconds(5),
        maxWait: Duration::millis(50),
    ))
    ->retry(
        Retry::new()
            ->maxAttempts(maxAttempts: 3)
            ->withExponential(baseMs: 10, multiplier: 2.0, capMs: 100),
    )
    ->circuitBreaker(new CircuitBreaker(
        config: new BreakerConfig(
            name: 'flaky-service',
            failureThreshold: Ratio::of(failures: 5, window: 10, within: Duration::seconds(60)),
            cooldown: Duration::seconds(30),
            successThreshold: 1,
            isFailure: static fn(\Throwable $e): bool => $e instanceof \RuntimeException,
        ),
        storage: new InMemoryStorage(),
        clock: new SystemClock(),
    ))
    ->build();

$attempt = 0;
$result = $pipeline->call(function () use (&$attempt): string {
    if (++$attempt < 2) {
        throw new \RuntimeException('transient error');
    }

    return 'succeeded';
});

printf("result: %s (after %d attempt(s))\n", $result, $attempt);
