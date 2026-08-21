<?php

declare(strict_types=1);

namespace Rasuvaeff\Resilience\Tests;

use Rasuvaeff\Bulkhead\BulkheadFullException;
use Rasuvaeff\Bulkhead\InMemoryBulkheadStore;
use Rasuvaeff\Bulkhead\SharedBulkhead;
use Rasuvaeff\CircuitBreaker\BreakerConfig;
use Rasuvaeff\CircuitBreaker\CircuitBreaker;
use Rasuvaeff\CircuitBreaker\CircuitOpenException;
use Rasuvaeff\CircuitBreaker\CircuitState;
use Rasuvaeff\CircuitBreaker\Clock\FakeClock;
use Rasuvaeff\CircuitBreaker\InMemoryStorage;
use Rasuvaeff\CircuitBreaker\Ratio;
use Rasuvaeff\CircuitBreaker\Storage;
use Rasuvaeff\CircuitBreaker\StorageFailure;
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\Resilience\CompiledPipeline;
use Rasuvaeff\Resilience\Pipeline;
use Rasuvaeff\Retry\Retry;
use Rasuvaeff\Retry\RetryExhausted;
use Rasuvaeff\Retry\Sleeper\FakeSleeper;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(CompiledPipeline::class)]
#[Covers(Pipeline::class)]
final class CompiledPipelineTest
{
    public function emptyPipelineIsAPlainCall(): void
    {
        $pipeline = Pipeline::for('svc')->build();

        Assert::same($pipeline->call(static fn(): string => 'ok'), 'ok');
    }

    public function nameIsExposed(): void
    {
        Assert::same(Pipeline::for('svc')->build()->name(), 'svc');
    }

    public function emptyNameIsRejected(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Pipeline name cannot be empty');

        Pipeline::for('');
    }

    public function callbackDoesNotNeedToBeAClosure(): void
    {
        $pipeline = Pipeline::for('svc')->build();

        Assert::same($pipeline->call('strrev'), '');
    }

    public function retryAloneRetriesTransientFailures(): void
    {
        $calls = 0;
        $pipeline = Pipeline::for('svc')
            ->retry($this->retry(maxAttempts: 3))
            ->build();

        $result = $pipeline->call(function () use (&$calls): string {
            if (++$calls < 3) {
                throw new \RuntimeException('transient');
            }

            return 'ok';
        });

        Assert::same($result, 'ok');
        Assert::same($calls, 3);
    }

    /**
     * The headline glue rule: once the breaker is open, the remaining retry
     * attempts are pointless - the callback must not run again and the retry
     * must not sleep out its backoff against a known-open circuit.
     */
    public function openCircuitStopsRetryImmediately(): void
    {
        $clock = new FakeClock();
        $breaker = $this->openedBreaker($clock);
        $sleeper = new FakeSleeper();
        $calls = 0;
        $pipeline = Pipeline::for('svc')
            ->retry($this->retry(maxAttempts: 5, sleeper: $sleeper))
            ->circuitBreaker($breaker)
            ->build();
        $caught = null;

        try {
            $pipeline->call(function () use (&$calls): string {
                ++$calls;

                return 'never';
            });
        } catch (\Throwable $e) {
            $caught = $e;
        }

        Assert::instanceOf($caught, CircuitOpenException::class);
        Assert::same($calls, 0);
        Assert::same($sleeper->delays(), []);
    }

    public function fullBulkheadIsNotRetriedByDefault(): void
    {
        $sleeper = new FakeSleeper();
        $calls = 0;
        $pipeline = Pipeline::for('svc')
            ->bulkhead($this->fullBulkhead())
            ->retry($this->retry(maxAttempts: 5, sleeper: $sleeper))
            ->build();
        $caught = null;

        try {
            $pipeline->call(function () use (&$calls): string {
                ++$calls;

                return 'never';
            });
        } catch (\Throwable $e) {
            $caught = $e;
        }

        Assert::instanceOf($caught, BulkheadFullException::class);
        Assert::same($calls, 0);
        Assert::same($sleeper->delays(), []);
    }

    public function retryOnBulkheadFullOptsBackIntoRetrying(): void
    {
        $sleeper = new FakeSleeper();
        $pipeline = Pipeline::for('svc')
            ->bulkhead($this->fullBulkhead())
            ->retry($this->retry(maxAttempts: 3, sleeper: $sleeper))
            ->retryOnBulkheadFull()
            ->build();
        $caught = null;

        try {
            $pipeline->call(static fn(): string => 'never');
        } catch (\Throwable $e) {
            $caught = $e;
        }

        Assert::instanceOf($caught, RetryExhausted::class);
        Assert::instanceOf($caught->lastException, BulkheadFullException::class);
        Assert::same($sleeper->delays(), [1, 1]);
    }

    public function breakerStorageFailureIsNotRetried(): void
    {
        $sleeper = new FakeSleeper();
        $pipeline = Pipeline::for('svc')
            ->retry($this->retry(maxAttempts: 5, sleeper: $sleeper))
            ->circuitBreaker($this->breakerWithThrowingStorage())
            ->build();
        $caught = null;

        try {
            $pipeline->call(static fn(): string => 'never');
        } catch (\Throwable $e) {
            $caught = $e;
        }

        Assert::instanceOf($caught, StorageFailure::class);
        Assert::same($sleeper->delays(), []);
    }

    /**
     * The slot is requested per attempt and is free while retry sleeps:
     * with the bulkhead innermost, active concurrency during the backoff
     * window is zero, not one.
     */
    public function bulkheadSlotIsFreeDuringRetrySleeps(): void
    {
        $store = new InMemoryBulkheadStore();
        $bulkhead = new SharedBulkhead(
            name: 'svc',
            maxConcurrent: 1,
            store: $store,
            lease: Duration::seconds(5),
            maxWait: Duration::zero(),
        );
        $activeDuringSleep = null;
        $sleeper = new class($store, $activeDuringSleep) implements \Rasuvaeff\Retry\Sleeper\SleeperInterface {
            public function __construct(
                private readonly InMemoryBulkheadStore $store,
                private ?int &$activeDuringSleep,
            ) {}

            #[\Override]
            public function sleepMs(int $ms): void
            {
                $this->activeDuringSleep = $this->store->activeCount(name: 'svc');
            }
        };
        $calls = 0;
        $pipeline = Pipeline::for('svc')
            ->bulkhead($bulkhead)
            ->retry($this->retry(maxAttempts: 2, sleeper: $sleeper))
            ->build();

        $result = $pipeline->call(function () use (&$calls): string {
            if (++$calls < 2) {
                throw new \RuntimeException('transient');
            }

            return 'ok';
        });

        Assert::same($result, 'ok');
        Assert::same($activeDuringSleep, 0);
    }

    /**
     * Default order: every attempt is one breaker outcome, so a breaker with
     * a threshold of 2 opens mid-retry and cuts the loop short.
     */
    public function breakerInsideRetryCountsEveryAttempt(): void
    {
        $breaker = $this->breaker(failures: 2, window: 5);
        $sleeper = new FakeSleeper();
        $calls = 0;
        $pipeline = Pipeline::for('svc')
            ->retry($this->retry(maxAttempts: 5, sleeper: $sleeper))
            ->circuitBreaker($breaker)
            ->build();
        $caught = null;

        try {
            $pipeline->call(function () use (&$calls): string {
                ++$calls;

                throw new \RuntimeException('down');
            });
        } catch (\Throwable $e) {
            $caught = $e;
        }

        // Attempts 1 and 2 run and fail; their failures open the breaker, so
        // attempt 3's admission is rejected and the glue stops the loop.
        Assert::instanceOf($caught, CircuitOpenException::class);
        Assert::same($calls, 2);
        Assert::same($breaker->state(), CircuitState::Open);
    }

    /**
     * breakerOutsideRetry(): the whole retry loop is ONE breaker outcome -
     * a transient blip fixed by a retry never reaches the failure ratio.
     */
    public function breakerOutsideRetryCountsTheWholeLoopOnce(): void
    {
        $breaker = $this->breaker(failures: 2, window: 5);
        $calls = 0;
        $pipeline = Pipeline::for('svc')
            ->retry($this->retry(maxAttempts: 3))
            ->circuitBreaker($breaker)
            ->breakerOutsideRetry()
            ->build();

        $result = $pipeline->call(function () use (&$calls): string {
            if (++$calls < 3) {
                throw new \RuntimeException('transient');
            }

            return 'ok';
        });

        Assert::same($result, 'ok');
        Assert::same($calls, 3);
        Assert::same($breaker->state(), CircuitState::Closed);
    }

    public function fallbackReceivesTheTerminalException(): void
    {
        $pipeline = Pipeline::for('svc')
            ->circuitBreaker($this->openedBreaker(new FakeClock()))
            ->build();

        $result = $pipeline->call(
            callback: static fn(): string => 'never',
            fallback: static fn(\Throwable $e): string => $e instanceof CircuitOpenException ? 'degraded' : 'unexpected',
        );

        Assert::same($result, 'degraded');
    }

    public function fallbackReceivesRetryExhaustedFromTheRetryLayer(): void
    {
        $pipeline = Pipeline::for('svc')
            ->retry($this->retry(maxAttempts: 2))
            ->build();

        $result = $pipeline->call(
            callback: static fn(): string => throw new \RuntimeException('down'),
            fallback: static fn(\Throwable $e): string => $e instanceof RetryExhausted ? 'degraded' : 'unexpected',
        );

        Assert::same($result, 'degraded');
    }

    public function fallbackReceivesTheCallbacksOwnExceptionWithNoLayers(): void
    {
        $pipeline = Pipeline::for('svc')->build();

        $result = $pipeline->call(
            callback: static fn(): string => throw new \DomainException('raw'),
            fallback: static fn(\Throwable $e): string => $e->getMessage(),
        );

        Assert::same($result, 'raw');
    }

    public function userStopIfPredicatesArePreserved(): void
    {
        $calls = 0;
        $pipeline = Pipeline::for('svc')
            ->retry(
                $this->retry(maxAttempts: 5)
                    ->stopIf(predicate: static fn(\Throwable $e): bool => $e instanceof \DomainException),
            )
            ->build();
        $caught = null;

        try {
            $pipeline->call(function () use (&$calls): string {
                ++$calls;

                throw new \DomainException('permanent');
            });
        } catch (\Throwable $e) {
            $caught = $e;
        }

        Assert::instanceOf($caught, \DomainException::class);
        Assert::same($calls, 1);
    }

    public function buildDoesNotMutateTheUsersRetryBuilder(): void
    {
        $userRetry = $this->retry(maxAttempts: 3);
        $calls = 0;

        Pipeline::for('svc')->retry($userRetry)->circuitBreaker($this->openedBreaker(new FakeClock()))->build();

        // The user's builder, used standalone afterwards, must still retry
        // CircuitOpenException freely (no glue leaked into it).
        try {
            $userRetry->run(operation: function () use (&$calls): string {
                ++$calls;

                throw new CircuitOpenException(breakerName: 'svc', retryAfter: new \DateTimeImmutable());
            });
        } catch (RetryExhausted) {
            // expected
        }

        Assert::same($calls, 3);
    }

    private function retry(int $maxAttempts, ?\Rasuvaeff\Retry\Sleeper\SleeperInterface $sleeper = null): Retry
    {
        return Retry::new()
            ->maxAttempts(maxAttempts: $maxAttempts)
            ->withFixed(delayMs: 1)
            ->withSleeper(sleeper: $sleeper ?? new FakeSleeper());
    }

    private function breaker(int $failures, int $window, ?FakeClock $clock = null): CircuitBreaker
    {
        return new CircuitBreaker(
            config: new BreakerConfig(
                name: 'svc',
                failureThreshold: Ratio::of(failures: $failures, window: $window, within: Duration::seconds(60)),
                cooldown: Duration::seconds(30),
                successThreshold: 1,
                isFailure: static fn(\Throwable $e): bool => !$e instanceof BulkheadFullException,
            ),
            storage: new InMemoryStorage(),
            clock: $clock ?? new FakeClock(),
        );
    }

    private function openedBreaker(FakeClock $clock): CircuitBreaker
    {
        $breaker = $this->breaker(failures: 1, window: 1, clock: $clock);

        try {
            $breaker->call(callback: static fn(): string => throw new \RuntimeException('down'));
        } catch (\RuntimeException) {
            // expected - opens the breaker
        }

        return $breaker;
    }

    private function fullBulkhead(): SharedBulkhead
    {
        $store = new InMemoryBulkheadStore();
        $store->tryAcquire('svc', 1, Duration::seconds(60));

        return new SharedBulkhead(
            name: 'svc',
            maxConcurrent: 1,
            store: $store,
            lease: Duration::seconds(60),
            maxWait: Duration::zero(),
        );
    }

    private function breakerWithThrowingStorage(): CircuitBreaker
    {
        $storage = new class implements Storage {
            #[\Override]
            public function admit(
                string $key,
                BreakerConfig $config,
                \DateTimeImmutable $now,
                string $attemptId,
            ): \Rasuvaeff\CircuitBreaker\AdmissionResult {
                throw new \RuntimeException('redis gone');
            }

            #[\Override]
            public function recordOutcome(
                string $key,
                \Rasuvaeff\CircuitBreaker\Outcome $outcome,
                BreakerConfig $config,
                \DateTimeImmutable $now,
                \Rasuvaeff\CircuitBreaker\Admission $admission,
                \DateTimeImmutable $admittedAt,
                string $attemptId,
            ): \Rasuvaeff\CircuitBreaker\OutcomeResult {
                throw new \RuntimeException('redis gone');
            }

            #[\Override]
            public function snapshot(string $key): \Rasuvaeff\CircuitBreaker\StateRecord
            {
                throw new \RuntimeException('redis gone');
            }

            #[\Override]
            public function forceState(
                string $key,
                \Rasuvaeff\CircuitBreaker\CircuitState $state,
                \DateTimeImmutable $now,
            ): ?\Rasuvaeff\CircuitBreaker\CircuitTransition {
                return null;
            }
        };

        return new CircuitBreaker(
            config: new BreakerConfig(
                name: 'svc',
                failureThreshold: Ratio::of(failures: 1, window: 1, within: Duration::seconds(60)),
                cooldown: Duration::seconds(30),
                successThreshold: 1,
                isFailure: static fn(\Throwable $e): bool => true,
            ),
            storage: $storage,
            clock: new FakeClock(),
        );
    }
}
