<?php

declare(strict_types=1);

namespace Rasuvaeff\Resilience\Tests;

use Rasuvaeff\Bulkhead\Bulkhead;
use Rasuvaeff\Bulkhead\InMemoryBulkheadStore;
use Rasuvaeff\Bulkhead\SharedBulkhead;
use Rasuvaeff\Bulkhead\Sleeper\FakeSleeper as BulkheadSleeper;
use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitBreaker;
use Rasuvaeff\CircuitBreaker\Clock\FakeClock;
use Rasuvaeff\CircuitBreaker\InMemoryStorage;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextCancelledException;
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Context\Testing\FakeTimeSource;
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\Resilience\CompiledPipeline;
use Rasuvaeff\Resilience\Pipeline;
use Rasuvaeff\Resilience\Rejection;
use Rasuvaeff\Retry\Retry;
use Rasuvaeff\Retry\Sleeper\SleeperInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CompiledPipeline::class)]
final class CompiledPipelineContextTest
{
    public function theCallbackReceivesTheContextOnlyWhenOneIsGiven(): void
    {
        $pipeline = Pipeline::for('svc')->build();
        [$context] = Context::background()->withCancel();

        Assert::same($pipeline->call(static fn(Context $c): bool => $c === $context, context: $context), expected: true);
        // An internal function would reject an extra argument.
        Assert::true(\is_int($pipeline->call('time')));
    }

    public function aTerminatedContextRunsNothing(): void
    {
        [$context, $controller] = Context::background()->withCancel();
        $controller->cancel();
        $ran = false;

        try {
            Pipeline::for('svc')->build()->call(static function () use (&$ran): void {
                $ran = true;
            }, context: $context);
        } catch (ContextCancelledException) {
            Assert::false($ran);

            return;
        }

        throw new \LogicException('a cancellation was expected');
    }

    public function aCancelledCallerSkipsTheFallback(): void
    {
        [$context, $controller] = Context::background()->withCancel();
        $controller->cancel();
        $fallbackCalls = 0;

        try {
            Pipeline::for('svc')->build()->call(
                static fn(): string => 'unreachable',
                fallback: static function () use (&$fallbackCalls): string {
                    $fallbackCalls++;

                    return 'cached';
                },
                context: $context,
            );
        } catch (ContextCancelledException) {
            Assert::same($fallbackCalls, 0);

            return;
        }

        throw new \LogicException('a cancellation was expected');
    }

    public function anExpiredBudgetStillReachesTheFallback(): void
    {
        [$context] = Context::background(new FakeTimeSource())->withTimeout(0.0);

        $result = Pipeline::for('svc')->build()->call(
            static fn(): string => 'unreachable',
            fallback: static fn(\Throwable $e): string => $e instanceof ContextExpiredException ? 'cached' : 'other',
            context: $context,
        );

        Assert::same($result, 'cached');
    }

    public function retryStopsWhenTheBudgetRunsOut(): void
    {
        $clock = new FakeTimeSource();
        [$context] = Context::background($clock)->withTimeout(0.25);
        $attempts = 0;
        $pipeline = Pipeline::for('svc')
            ->retry(Retry::new()->maxAttempts(maxAttempts: 10)->withFixed(delayMs: 100)->withSleeper(sleeper: $this->retrySleeper($clock)))
            ->build();

        try {
            $pipeline->call(static function () use (&$attempts): never {
                $attempts++;

                throw new \RuntimeException('downstream failed');
            }, context: $context);
        } catch (ContextExpiredException) {
            Assert::same($attempts, 3);

            return;
        }

        throw new \LogicException('the budget was expected to stop the retries');
    }

    public function theBulkheadWaitIsCappedByTheBudget(): void
    {
        $clock = new FakeTimeSource();
        [$context] = Context::background($clock)->withTimeout(0.12);
        $store = new InMemoryBulkheadStore();
        $store->tryAcquire('svc', 1, Duration::seconds(60));
        $sleeper = new BulkheadSleeper(onSleep: static fn(Duration $d) => $clock->advanceMs($d->toMillis()));
        $mapped = 0;
        $pipeline = Pipeline::for('svc')
            ->bulkhead(new SharedBulkhead(
                name: 'svc',
                maxConcurrent: 1,
                store: $store,
                lease: Duration::seconds(60),
                maxWait: Duration::seconds(5),
                sleeper: $sleeper,
            ))
            ->onRejected(static function (\Throwable $rejection) use (&$mapped): \Throwable {
                $mapped++;

                return $rejection;
            })
            ->build();

        try {
            $pipeline->call(static fn(): int => 1, context: $context);
        } catch (ContextExpiredException $expired) {
            Assert::false(Rejection::is($expired));
            Assert::same($mapped, 0);
            Assert::same($sleeper->totalSlept()->toMillis(), 120);

            return;
        }

        throw new \LogicException('the budget was expected to end the slot wait');
    }

    public function aPlainBulkheadStillWorksUnderAContext(): void
    {
        $bulkhead = new class implements Bulkhead {
            public int $calls = 0;

            #[\Override]
            public function call(callable $callback): mixed
            {
                $this->calls++;

                return $callback();
            }

            #[\Override]
            public function availableSlots(): int
            {
                return 1;
            }
        };
        [$context] = Context::background()->withCancel();

        $result = Pipeline::for('svc')->bulkhead($bulkhead)->build()->call(static fn(): string => 'ok', context: $context);

        Assert::same($result, 'ok');
        Assert::same($bulkhead->calls, 1);
    }

    public function aTerminatedContextNeverBecomesABreakerOutcomeInEitherOrder(): void
    {
        foreach ([false, true] as $outside) {
            $breaker = $this->breaker();
            $builder = Pipeline::for('svc')->circuitBreaker($breaker)->retry(Retry::new()->maxAttempts(maxAttempts: 1));
            $pipeline = ($outside ? $builder->breakerOutsideRetry() : $builder)->build();
            [$context, $controller] = Context::background()->withCancel();
            $controller->cancel();

            try {
                $pipeline->call(static fn(): int => 1, context: $context);
            } catch (ContextCancelledException) {
            }

            Assert::same($breaker->metrics()->successes() + $breaker->metrics()->failures(), 0);
        }
    }

    public function retryChecksTheBudgetBeforeEachBreakerAttempt(): void
    {
        $breaker = $this->breaker();
        $clock = new FakeTimeSource();
        [$context] = Context::background($clock)->withTimeout(0.15);
        $pipeline = Pipeline::for('svc')
            ->circuitBreaker($breaker)
            ->retry(Retry::new()->maxAttempts(maxAttempts: 5)->withFixed(delayMs: 100)->withSleeper(sleeper: $this->retrySleeper($clock)))
            ->build();

        try {
            $pipeline->call(static fn(): never => throw new \RuntimeException('down'), context: $context);
        } catch (ContextExpiredException) {
            Assert::same($breaker->metrics()->failures(), 2);

            return;
        }

        throw new \LogicException('the budget was expected to stop the pipeline');
    }

    public function withoutAContextACancellationReachesTheFallback(): void
    {
        $result = Pipeline::for('svc')->build()->call(
            static fn(): never => throw new ContextCancelledException(),
            fallback: static fn(\Throwable $e): string => $e::class,
        );

        Assert::same($result, ContextCancelledException::class);
    }

    public function anInnerContextCancellationReachesTheFallback(): void
    {
        [$context] = Context::background()->withCancel();

        $result = Pipeline::for('svc')->build()->call(
            static fn(): never => throw new ContextCancelledException(),
            fallback: static fn(\Throwable $e): string => 'fallback',
            context: $context,
        );

        Assert::same($result, 'fallback');
    }

    private function retrySleeper(FakeTimeSource $clock): SleeperInterface
    {
        return new readonly class ($clock) implements SleeperInterface {
            public function __construct(private FakeTimeSource $clock) {}

            #[\Override]
            public function sleepMs(int $ms): void
            {
                $this->clock->advanceMs($ms);
            }
        };
    }

    private function breaker(): CircuitBreaker
    {
        return new CircuitBreaker(
            config: new BreakerConfig(
                name: 'svc',
                failureThreshold: Ratio::of(failures: 100, window: 100, within: Duration::seconds(60)),
                cooldown: Duration::seconds(30),
                successThreshold: 1,
                isFailure: static fn(\Throwable $e): bool => true,
            ),
            storage: new InMemoryStorage(),
            clock: new FakeClock(),
        );
    }
}
