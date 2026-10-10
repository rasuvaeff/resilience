# rasuvaeff/resilience

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/resilience/v/stable.svg)](https://packagist.org/packages/rasuvaeff/resilience)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/resilience/downloads.svg)](https://packagist.org/packages/rasuvaeff/resilience)
[![Build](https://github.com/rasuvaeff/resilience/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/resilience/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/resilience/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/resilience/actions/workflows/static-analysis.yml)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/resilience/level.svg)](https://shepherd.dev/github/rasuvaeff/resilience)
[![License](https://poser.pugx.org/rasuvaeff/resilience/license.svg)](LICENSE.md)

Resilience-pipeline для PHP: композирует [`rasuvaeff/retry`](https://github.com/rasuvaeff/retry),
[`rasuvaeff/circuit-breaker`](https://github.com/rasuvaeff/circuit-breaker) и
[`rasuvaeff/bulkhead`](https://github.com/rasuvaeff/bulkhead) в правильном
порядке со встроенным связующим exception-кодом — PHP-аналог `ResiliencePipeline`
из Polly / `Decorators` из resilience4j.

[English version](README.md)

> Используете AI-ассистента? Дайте ему [llms.txt](llms.txt) — компактный
> самодостаточный справочник API.

## Зачем

Три листовых пакета композируются обычными замыканиями — но *правильная*
композиция требует связующего кода, в котором легко ошибиться:

| Правило | Почему |
|---|---|
| Retry обязан останавливаться на `CircuitOpenException` | контур открыт; новые попытки бессмысленны и враждебны к даунстриму |
| Retry обязан останавливаться на `BulkheadFullException` (по умолчанию) | насыщение — не transient-ошибка; слепые повторы усиливают перегрузку |
| Retry обязан останавливаться на `StorageFailure` breaker'а | отказ инфраструктуры — не вердикт о даунстриме |
| Bulkhead — самый внутренний | слот занят только пока callback реально выполняется — не во время retry-sleep'ов |
| Breaker снаружи bulkhead | открытый контур отклоняет вызов до того, как запрошен слот |
| Breaker внутри или снаружи retry | два легитимных порядка с разной семантикой — см. ниже |

`Pipeline` фиксирует порядок вложенности и добавляет связующий код автоматически,
не модифицируя переданные вами объекты.

## Требования

- PHP 8.3+
- `rasuvaeff/retry` ^1.3, `rasuvaeff/circuit-breaker` ^1.3,
  `rasuvaeff/bulkhead` ^1.3, `rasuvaeff/duration` ^1.1 (ставятся
  автоматически)

## Установка

```bash
composer require rasuvaeff/resilience
```

## Использование

Блок ниже исполняемый — `composer build` прогоняет его против реального API
через [rasuvaeff/doc-exec](https://github.com/rasuvaeff/doc-exec), так что
значения `// =>` не могут протухнуть. Он использует in-memory backend'ы; в
продакшене bulkhead и breaker указывают на Redis/APCu — код pipeline не
меняется.

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

// Листья настраиваются как обычно - pipeline берёт их готовыми и ничего
// не знает о хранилищах, backend'ах и политиках.
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
    ->build(); // immutable, переиспользуемый

// Transient-сбой повторяется до успеха:
$attempt = 0;
$pipeline->call(function () use (&$attempt): string {
    if (++$attempt < 2) {
        throw new \RuntimeException('transient error');
    }

    return 'succeeded';
}); // => "succeeded"
$attempt; // => 2

// Опциональный fallback на самом внешнем уровне получает терминальное исключение:
$pipeline->call(
    callback: static fn(): string => 'primary',
    fallback: static fn(\Throwable $e): string => $e instanceof CircuitOpenException ? 'degraded' : 'unexpected',
); // => "primary"
```

Каждый слой опционален, в любом сочетании; пустой pipeline — обычный вызов
(удобно для условной сборки).

### Порядок вложенности

По умолчанию — `retry(circuitBreaker(bulkhead(callback)))`:

- **bulkhead внутри** — слот занят только пока callback реально
  выполняется, запрашивается заново на каждую попытку; retry-sleep'ы не
  тратят бюджет конкурентности;
- **breaker вокруг bulkhead** — открытый контур отклоняет вызов до
  запроса слота;
- **retry снаружи** — с добавленным связующим кодом.

### Breaker внутри или снаружи retry

| | `BreakerInsideRetry` (по умолчанию) | `->breakerOutsideRetry()` |
|---|---|---|
| Один исход breaker'а на | попытку | логическую операцию (весь retry-цикл) |
| Контур открылся посреди цикла | мгновенно обрубает оставшиеся попытки | не может прервать цикл |
| Transient-сбой, исправленный повтором | всё равно попадает в failure ratio | никогда не доходит до failure ratio |
| Выбирать, когда | breaker должен видеть реальное здоровье per-call | breaker должен судить операции, а не попытки |
| `isFailure` получает | собственное исключение callback'а (и, поскольку breaker оборачивает bulkhead, `BulkheadFullException`) | то, что бросает retry-цикл — `RetryExhausted` при исчерпании (последнее downstream-исключение в его `lastException`), никогда исключение callback'а напрямую |

### Связующий exception-код

`build()` добавляет `stopIf`-предикаты к **копии** вашего retry-builder'а
(`Retry` immutable — переданный экземпляр не модифицируется, ваши
собственные `stopIf`/`retryIf` сохраняются):

- `CircuitOpenException` → стоп, всегда;
- `StorageFailure` (отказ хранилища breaker'а) → стоп, всегда;
- `BulkheadFullException` → стоп по умолчанию; вернуть повторы можно через
  `->retryOnBulkheadFull()`, когда слот ожидаемо освободится в пределах
  backoff-окна.

Все терминальные исключения выходят наружу **без изменений** —
`RetryExhausted`, `CircuitOpenException`, `BulkheadFullException`,
`StorageFailure` либо собственное исключение callback'а. Опциональный
`fallback` получает то из них, которое завершило вызов.

### Отказы и доменные исключения

Вызов может завершиться, так и не запустив callback: circuit открыт, все
слоты заняты или хранилище breaker'а упало до запуска callback'а.
`Rejection::is()` распознаёт
ровно эти три случая, а `Rejection::retryAfter()` превращает подсказку листа
в относительный `Duration` для заголовка `Retry-After` или задержки
повторной постановки в очередь. `onRejected()` один раз на pipeline
переводит их в доменное исключение вместо `try/catch` вокруг каждого вызова:

```php doc-exec
use Rasuvaeff\Bulkhead\BulkheadFullException;
use Rasuvaeff\Bulkhead\InMemoryBulkheadStore;
use Rasuvaeff\Bulkhead\SharedBulkhead;
use Rasuvaeff\Duration\Duration;
use Rasuvaeff\Resilience\Pipeline;
use Rasuvaeff\Resilience\Rejection;

final class BillingUnavailable extends \RuntimeException {}

$store = new InMemoryBulkheadStore();
$store->tryAcquire('billing', 1, Duration::seconds(5)); // единственный слот занят

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

- Маппер работает на самом внешнем уровне, до `fallback`; fallback получает
  уже переведённое исключение. Любое другое исключение проходит без
  изменений.
- `StorageFailure` считается отказом, только если хранилище упало на
  `admit` или на `snapshot` в ветке отказа. Исключение из `recordOutcome`
  значит, что callback уже выполнился и его результат не записан: это не
  отказ, повторная постановка в очередь может выполнить неидемпотентный
  вызов дважды.
- `RetryExhausted` никогда не считается отказом, даже если отказом была
  последняя попытка: одна из предыдущих могла дойти до downstream.
- `retryAfter()` — для `CircuitOpenException` время до перехода в half-open
  (по переданным часам, по умолчанию системным), для
  `BulkheadFullException` — lease слота, то есть верхняя граница. Для
  `StorageFailure` — `null`.

`call()` generic: Psalm выводит `string` выше из callback'а, `@var` в месте
вызова не нужен.

### Публичный API

| Тип | Описание |
|---|---|
| `Pipeline` | Builder: `for(name)`, `bulkhead(Bulkhead)`, `retry(Retry)`, `circuitBreaker(CircuitBreakerInterface)`, `onRejected(Closure)`, `breakerOutsideRetry()`, `retryOnBulkheadFull()`, `build()` |
| `CompiledPipeline` | Immutable-результат: generic `call(callable(): T, ?callable(Throwable): T $fallback): T`, `name()` |
| `Rejection` | `is(Throwable): bool`, `retryAfter(Throwable, ?ClockInterface): ?Duration` |
| `PipelineOrder` | Enum: `BreakerInsideRetry`, `BreakerOutsideRetry` |

## Безопасность

- Pipeline не добавляет ни I/O, ни хранилищ, ни собственных типов
  исключений; поверхность безопасности — это поверхность листовых пакетов
  (см. их README — в частности fail-closed семантику хранилища у bulkhead
  и контракт `StorageFailure` у breaker'а).
- Поскольку breaker оборачивает bulkhead, `BulkheadFullException` проходит
  через классификатор `isFailure` breaker'а. **Классифицируйте его как
  не-отказ** (`Ignored`), если только вы не хотите сознательно, чтобы
  локальное насыщение открывало контур против здорового даунстрима:

```php
isFailure: static fn(\Throwable $e): bool =>
    !$e instanceof \Rasuvaeff\Bulkhead\BulkheadFullException
    && $e instanceof \Psr\Http\Client\ClientExceptionInterface,
```

## Примеры

См. [`examples/`](examples/) — исполняемые скрипты на in-memory backend'ах
листовых пакетов (сервер не нужен).

## Разработка

```bash
make install
make build      # validate → normalize → require-checker → cs → psalm → test
make release-check
```

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
