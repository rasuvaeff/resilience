<?php

declare(strict_types=1);

namespace Rasuvaeff\Resilience\Tests;

use Rasuvaeff\Context\ContextCancelledException;
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Resilience\ContextOutcome;
use Rasuvaeff\Resilience\Rejection;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(ContextOutcome::class)]
final class ContextOutcomeTest
{
    #[DataProvider('contextExceptionProvider')]
    public function contextExceptionsAreNeverFailures(\Throwable $exception): void
    {
        $isFailure = ContextOutcome::ignoring(static fn(\Throwable $e): bool => true);

        Assert::false($isFailure($exception));
        Assert::false(Rejection::is($exception));
    }

    public static function contextExceptionProvider(): iterable
    {
        yield 'cancelled' => [new ContextCancelledException()];
        yield 'expired' => [new ContextExpiredException(1)];
    }

    public function everythingElseIsClassifiedByTheWrappedClassifier(): void
    {
        $isFailure = ContextOutcome::ignoring(static fn(\Throwable $e): bool => $e instanceof \DomainException);

        Assert::true($isFailure(new \DomainException('down')));
        Assert::false($isFailure(new \LogicException('caller bug')));
    }
}
