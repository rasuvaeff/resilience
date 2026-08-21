<?php

declare(strict_types=1);

namespace Rasuvaeff\Resilience\Benchmarks;

use Rasuvaeff\Resilience\CompiledPipeline;
use Rasuvaeff\Resilience\Pipeline;
use Rasuvaeff\Retry\Retry;
use Testo\Bench;

final class CompiledPipelineBench
{
    private static ?CompiledPipeline $empty = null;

    private static ?CompiledPipeline $withRetry = null;

    /**
     * The overhead a pipeline adds over a bare closure invocation - the only
     * CPU cost this package itself contributes per call.
     */
    #[Bench(
        callables: [
            'retry-wrapped' => [self::class, 'retryWrappedCall'],
        ],
        calls: 100_000,
        iterations: 10,
    )]
    public static function emptyPipelineCall(): mixed
    {
        self::$empty ??= Pipeline::for('bench')->build();

        return self::$empty->call(static fn(): int => 1);
    }

    public static function retryWrappedCall(): mixed
    {
        self::$withRetry ??= Pipeline::for('bench')
            ->retry(Retry::new()->maxAttempts(maxAttempts: 1)->withImmediate())
            ->build();

        return self::$withRetry->call(static fn(): int => 1);
    }

    #[Bench(
        callables: [
            'empty' => [self::class, 'buildEmptyFromScratch'],
        ],
        calls: 100_000,
        iterations: 10,
    )]
    public static function buildFromScratch(): CompiledPipeline
    {
        return Pipeline::for('bench')
            ->retry(Retry::new()->maxAttempts(maxAttempts: 1)->withImmediate())
            ->build();
    }

    public static function buildEmptyFromScratch(): CompiledPipeline
    {
        return Pipeline::for('bench')->build();
    }
}
