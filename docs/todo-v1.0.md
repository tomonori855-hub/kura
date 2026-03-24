> Japanese version: [todo-v1.0-ja.md](todo-v1.0-ja.md)

# v1.0 TODO: Octane Support

Tracking the changes required to support Laravel Octane and guarantee per-request version consistency in all environments.

---

## Problems to solve

### A — Version can change mid-request (Octane)

`CachedVersionResolver` stores the resolved version in a PHP variable with a TTL check (`microtime(true) - $cachedAt < $ttl`). In Octane's persistent process, the cached value from a previous request may expire during a request, causing two queries within the same request to see different versions.

```
Octane process:
  cachedAt = 299 s ago (from previous request)
  → first query:  TTL still valid → "v1.0.0"
  → 2 s later:    TTL expires     → re-resolve → "v2.0.0"
  → same request sees both v1.0.0 and v2.0.0 ❌
```

PHP-FPM is not affected (new process per request), but the guarantee is missing by design.

### B — `setVersionOverride()` leaks across requests (Octane)

`KuraManager` is a singleton. Calling `setVersionOverride('v2.0.0')` sets `$versionOverride` permanently in the process with no way to clear it.

```
Request 1: X-Reference-Version header present → setVersionOverride('v2.0.0')
Request 2: no header → $versionOverride still 'v2.0.0' → all queries use v2.0.0 ❌
```

### C — auto-discover creates its own resolver, separate from the container

`KuraServiceProvider::autoDiscoverCsvTables()` instantiates `new CsvVersionResolver()` → `new CachedVersionResolver()` independently from the container binding. This means tables registered via auto-discover always use a CSV resolver even when `version.driver = 'database'` is configured.

### D — Clock is not injectable

`DatabaseVersionResolver` calls `new \DateTimeImmutable` internally. `CsvVersionResolver` accepts `$defaultNow` but only at construction time. Neither accepts a `ClockInterface`, making it impossible to inject request time or freeze time in tests.

---

## Solutions

### A + B — Reset at request boundary

Add `resetRequestCache()` to `CachedVersionResolver` (clears PHP var only, keeps APCu):

```php
public function resetRequestCache(): void
{
    $this->cachedVersion = null;
    $this->cachedAt = null;
}
```

Add `resetForRequest()` to `KuraManager` (clears override and cached instances):

```php
public function resetForRequest(): void
{
    $this->versionOverride = null;
    $this->repositories = [];
    $this->processors = [];
}
```

Register an Octane listener in `KuraServiceProvider`:

```php
if (class_exists(\Laravel\Octane\Events\RequestReceived::class)) {
    $this->app['events']->listen(
        \Laravel\Octane\Events\RequestReceived::class,
        function () {
            $resolver = $this->app->make(VersionResolverInterface::class);
            if ($resolver instanceof CachedVersionResolver) {
                $resolver->resetRequestCache();
            }
            $this->app->make(KuraManager::class)->resetForRequest();
        }
    );
}
```

### C — Use the container resolver in auto-discover

```php
// before
$inner = new CsvVersionResolver($versionsFile);
$resolver = $cacheTtl > 0 ? new CachedVersionResolver($inner, ...) : $inner;

// after
$resolver = $this->app->make(VersionResolverInterface::class);
```

### D — Inject `ClockInterface`

Add `ClockInterface` (already exists as `Kura\Version\SystemClock` implementing PSR-20 `ClockInterface`) to `DatabaseVersionResolver` and `CsvVersionResolver` constructors with a default of `new SystemClock()`.

Add `tests/Support/FrozenClock.php` for tests:

```php
final class FrozenClock implements \Psr\Clock\ClockInterface
{
    public function __construct(private readonly \DateTimeImmutable $now) {}
    public function now(): \DateTimeImmutable { return $this->now; }
}
```

---

## Files to change

| File | Change |
|---|---|
| `src/Version/CachedVersionResolver.php` | Add `resetRequestCache()` |
| `src/Version/DatabaseVersionResolver.php` | Inject `ClockInterface` |
| `src/Loader/CsvVersionResolver.php` | Inject `ClockInterface` (replace `$defaultNow`) |
| `src/KuraManager.php` | Add `resetForRequest()` |
| `src/KuraServiceProvider.php` | Register Octane listener + unify auto-discover resolver |
| `tests/Support/FrozenClock.php` | **New**: frozen clock for tests |
| `tests/Version/CachedVersionResolverTest.php` | Add `resetRequestCache` tests |
| `tests/Version/DatabaseVersionResolverTest.php` | Switch to `FrozenClock` |
| `tests/Loader/CsvVersionResolverTest.php` | Keep backward compat (`resolveVersion($now)`) |

**No changes needed:**

| File | Reason |
|---|---|
| `src/CacheRepository.php` | `version()` is already dynamic |
| `src/CacheProcessor.php` | Calls `repository->version()` on every query |
| `src/Store/ApcuStore.php` | Stateless (version passed as parameter) |
| `src/Loader/CsvLoader.php` | Delegates to resolver |
| `src/Loader/EloquentLoader.php` | Already uses `VersionResolverInterface` |
| `src/Loader/QueryBuilderLoader.php` | Same |

---

## Breaking changes

None expected:
- `ClockInterface` defaults to `new SystemClock()` — existing code unchanged
- `CsvVersionResolver::resolveVersion(?DateTimeInterface $now)` kept for backward compat
- `resetRequestCache()` / `resetForRequest()` are new methods
- Octane listener is guarded by `class_exists` — no effect without Octane

---

## Verification

```bash
vendor/bin/phpunit
vendor/bin/phpunit --filter "CachedVersionResolverTest|DatabaseVersionResolverTest|CsvVersionResolverTest|DatabaseLoaderTest"
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/pint --test
```
