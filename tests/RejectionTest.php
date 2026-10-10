<?php

declare(strict_types=1);

namespace Rasuvaeff\Resilience\Tests;

use Rasuvaeff\Bulkhead\BulkheadFullException;
use Rasuvaeff\CircuitBreaker\CircuitOpenException;
use Rasuvaeff\CircuitBreaker\Clock\FakeClock;
use Rasuvaeff\CircuitBreaker\StorageFailure;
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\Resilience\Rejection;
use Rasuvaeff\Retry\ExhaustionReason;
use Rasuvaeff\Retry\RetryExhausted;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Rejection::class)]
final class RejectionTest
{
    #[DataProvider('classificationProvider')]
    public function classifies(\Throwable $e, bool $expected): void
    {
        Assert::same(Rejection::is($e), $expected);
    }

    public static function classificationProvider(): iterable
    {
        $open = new CircuitOpenException(breakerName: 'svc', retryAfter: new \DateTimeImmutable());

        yield 'open circuit' => [$open, true];
        yield 'full bulkhead' => [new BulkheadFullException(name: 'svc', maxConcurrent: 1), true];
        yield 'breaker store failed on admit' => [new StorageFailure(operation: 'admit', breakerName: 'svc', previous: new \RuntimeException('redis')), true];
        yield 'breaker store failed on the rejected-path snapshot' => [new StorageFailure(operation: 'snapshot', breakerName: 'svc', previous: new \RuntimeException('redis')), true];
        yield 'breaker store failed recording a finished call' => [new StorageFailure(operation: 'recordOutcome', breakerName: 'svc', previous: new \RuntimeException('redis')), false];
        yield 'breaker store failed recording a failed call' => [new StorageFailure(operation: 'recordOutcome', breakerName: 'svc', previous: new \RuntimeException('redis'), downstreamOutcome: new \RuntimeException('down')), false];
        yield 'breaker store failed forcing state' => [new StorageFailure(operation: 'forceState', breakerName: 'svc', previous: new \RuntimeException('redis')), false];
        yield 'downstream failure' => [new \RuntimeException('down'), false];
        yield 'retry exhausted on a rejection' => [new RetryExhausted(attempts: 2, lastException: $open, history: [], reason: ExhaustionReason::MaxAttempts), false];
    }

    public function retryAfterForAnOpenCircuitIsTheTimeLeft(): void
    {
        $clock = new FakeClock(new \DateTimeImmutable('2026-10-10 12:00:00.250000'));
        $e = new CircuitOpenException(breakerName: 'svc', retryAfter: new \DateTimeImmutable('2026-10-10 12:00:30.000000'));

        Assert::same(Rejection::retryAfter($e, $clock)?->toMicros(), 29_750_000);
    }

    public function retryAfterForAnOpenCircuitUsesTheSystemClockByDefault(): void
    {
        $e = new CircuitOpenException(breakerName: 'svc', retryAfter: new \DateTimeImmutable('+1 hour'));
        $left = Rejection::retryAfter($e)?->toMicros();

        Assert::true($left !== null && $left > 3_590_000_000 && $left <= 3_600_000_000);
    }

    public function retryAfterForAFullBulkheadIsTheLease(): void
    {
        $e = new BulkheadFullException(name: 'svc', maxConcurrent: 1, waited: Duration::zero(), lease: Duration::seconds(15));

        Assert::same(Rejection::retryAfter($e)?->toMicros(), 15_000_000);
    }

    public function retryAfterIsNullWithoutAHint(): void
    {
        Assert::null(Rejection::retryAfter(new BulkheadFullException(name: 'svc', maxConcurrent: 1)));
        Assert::null(Rejection::retryAfter(new StorageFailure(operation: 'admit', breakerName: 'svc', previous: new \RuntimeException('redis'))));
        Assert::null(Rejection::retryAfter(new \RuntimeException('down')));
    }
}
