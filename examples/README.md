# Examples

Runnable scripts on the in-memory backends of the leaf packages — no server
needed. Run from the package root after `composer install`:

```bash
php examples/01-full-pipeline.php
php examples/02-fallback-on-open-circuit.php
```

| Script | Shows | Needs server? |
|---|---|---|
| `01-full-pipeline.php` | All three layers composed: bulkhead + retry + circuit breaker; a transient failure retried to success | No |
| `02-fallback-on-open-circuit.php` | The glue in action: an open circuit stops the retry loop after zero callback invocations; the fallback degrades gracefully | No |
