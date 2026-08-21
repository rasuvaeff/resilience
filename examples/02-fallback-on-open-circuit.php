<?php

declare(strict_types=1);

use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitBreaker;
use Rasuvaeff\CircuitBreaker\CircuitOpenException;
use Rasuvaeff\CircuitBreaker\Clock\SystemClock;
use Rasuvaeff\CircuitBreaker\InMemoryStorage;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\Resilience\Pipeline;
use Rasuvaeff\Retry\Retry;

require dirname(__DIR__) . '/vendor/autoload.php';

$breaker = new CircuitBreaker(
    config: new BreakerConfig(
        name: 'payments',
        failureThreshold: Ratio::of(failures: 1, window: 1, within: Duration::seconds(60)),
        cooldown: Duration::seconds(30),
        successThreshold: 1,
        isFailure: static fn(\Throwable $e): bool => true,
    ),
    storage: new InMemoryStorage(),
    clock: new SystemClock(),
);

$pipeline = Pipeline::for('payments')
    ->retry(Retry::new()->maxAttempts(maxAttempts: 5)->withImmediate())
    ->circuitBreaker($breaker)
    ->build();

// First call fails and opens the breaker (threshold 1/1).
try {
    $pipeline->call(static fn(): string => throw new \RuntimeException('downstream down'));
} catch (\Throwable $e) {
    printf("first call: %s\n", $e::class);
}

// Second call: the open circuit rejects instantly, the glue stops the retry
// loop after zero callback invocations, and the fallback degrades gracefully.
$calls = 0;
$result = $pipeline->call(
    callback: function () use (&$calls): string {
        ++$calls;

        return 'never reached';
    },
    fallback: static fn(\Throwable $e): string => $e instanceof CircuitOpenException
        ? 'served from cache'
        : 'unexpected: ' . $e::class,
);

printf("second call: %s (callback invoked %d time(s))\n", $result, $calls);
