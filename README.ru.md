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
порядке со встроенным exception-глюкодом — PHP-аналог `ResiliencePipeline`
из Polly / `Decorators` из resilience4j.

[English version](README.md)

> Используете AI-ассистента? Дайте ему [llms.txt](llms.txt) — компактный
> самодостаточный справочник API.

## Зачем

Три листовых пакета композируются обычными замыканиями — но *правильная*
композиция требует глюкода, в котором легко ошибиться:

| Правило | Почему |
|---|---|
| Retry обязан останавливаться на `CircuitOpenException` | контур открыт; новые попытки бессмысленны и враждебны к даунстриму |
| Retry обязан останавливаться на `BulkheadFullException` (по умолчанию) | насыщение — не transient-ошибка; слепые повторы усиливают перегрузку |
| Retry обязан останавливаться на `StorageFailure` breaker'а | отказ инфраструктуры — не вердикт о даунстриме |
| Bulkhead — самый внутренний | слот занят только пока callback реально выполняется — не во время retry-sleep'ов |
| Breaker снаружи bulkhead | открытый контур отклоняет вызов до того, как запрошен слот |
| Breaker внутри или снаружи retry | два легитимных порядка с разной семантикой — см. ниже |

`Pipeline` фиксирует порядок вложенности и добавляет глюкод автоматически,
не модифицируя переданные вами объекты.

## Требования

- PHP 8.3+
- `rasuvaeff/retry` ^1.2.3, `rasuvaeff/circuit-breaker` ^1.2,
  `rasuvaeff/bulkhead` ^1.1.3, `rasuvaeff/duration` ^1.1 (ставятся
  автоматически)

## Установка

```bash
composer require rasuvaeff/resilience
```

## Использование

```php
use Rasuvaeff\Bulkhead\SharedBulkhead;
use Rasuvaeff\CircuitBreaker\CircuitBreaker;
use Rasuvaeff\Resilience\Pipeline;
use Rasuvaeff\Retry\Retry;

// Листья настраиваются как обычно - pipeline берёт их готовыми и ничего
// не знает о хранилищах, backend'ах и политиках.
$pipeline = Pipeline::for('stripe')
    ->bulkhead($bulkhead)          // SharedBulkhead, настроенный вами
    ->retry(
        Retry::new()
            ->maxAttempts(maxAttempts: 4)
            ->withExponential(baseMs: 100, multiplier: 2.0, capMs: 5_000),
    )
    ->circuitBreaker($breaker)     // CircuitBreaker, настроенный вами
    ->build();                     // immutable, переиспользуемый

$charge = $pipeline->call(fn() => $stripe->charges->create([/* ... */]));

// Опциональный fallback на самом внешнем уровне:
$charge = $pipeline->call(
    callback: fn() => $stripe->charges->create([/* ... */]),
    fallback: fn(\Throwable $e) => ChargeResult::queuedForRetry(),
);
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
- **retry снаружи** — с добавленным глюкодом.

### Breaker внутри или снаружи retry

| | `BreakerInsideRetry` (по умолчанию) | `->breakerOutsideRetry()` |
|---|---|---|
| Один исход breaker'а на | попытку | логическую операцию (весь retry-цикл) |
| Контур открылся посреди цикла | мгновенно обрубает оставшиеся попытки | не может прервать цикл |
| Transient-сбой, исправленный повтором | всё равно попадает в failure ratio | никогда не доходит до failure ratio |
| Выбирать, когда | breaker должен видеть реальное здоровье per-call | breaker должен судить операции, а не попытки |

### Exception-глюкод

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

### Публичный API

| Тип | Описание |
|---|---|
| `Pipeline` | Builder: `for(name)`, `bulkhead()`, `retry()`, `circuitBreaker()`, `breakerOutsideRetry()`, `retryOnBulkheadFull()`, `build()` |
| `CompiledPipeline` | Immutable-результат: `call(callable, ?callable $fallback): mixed`, `name()` |
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
