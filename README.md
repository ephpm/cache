# ephpm/cache

PSR-16 (SimpleCache) and PSR-6 cache implementations backed by
[ePHPm](https://github.com/ephpm/ephpm)'s embedded key-value store.

Unlike Redis or Memcached adapters, this package talks to ePHPm's **in-process**
KV store through the global `ephpm_kv_*` SAPI functions. There is no network
hop, no separate daemon to run, and in a clustered ePHPm deployment the store is
**gossip-replicated** across nodes automatically.

## Requirements

- PHP **8.2+**
- The application must be served by an **ePHPm** binary — any tagged release
  works (the `ephpm_kv_*` functions have shipped since ePHPm v0.1.0;
  `ephpm_kv_flush_all()`, used by `clear()`, since v0.1.2; current release:
  v0.8.6). The functions are provided by the SAPI and are available in both
  FPM-style and worker (long-lived) request modes. Outside ePHPm the
  constructors throw a `RuntimeException`.

## Installation

ePHPm packages are distributed via their GitHub repositories, not Packagist.
Add this repo as a Composer `vcs` repository, then require the package
(`ephpm/cache` is tagged, so `^0.1` resolves):

```bash
composer config repositories.ephpm/cache vcs https://github.com/ephpm/cache
composer require ephpm/cache:^0.1
```

This package `provide`s `psr/simple-cache-implementation` and
`psr/cache-implementation` (both `3.0`), so frameworks that depend on those
virtual packages will accept it.

## Why use it

- **In-process, zero-copy-ish reads.** The KV store lives in the same address
  space as your request. No TCP round trip to Redis, no serialization over the
  wire beyond PHP's own `serialize()`.
- **Cluster-replicated for free.** When ePHPm runs clustered, writes propagate
  to other nodes via the gossip tier -- a warm cache follows your traffic without
  an external cache cluster.
- **No extra moving parts.** One binary. Nothing else to deploy, monitor, or
  secure.

## PSR-16 (SimpleCache) usage

```php
use Ephpm\Cache\Psr16\Cache;

$cache = new Cache();                 // no default TTL (entries persist)
$cache = new Cache(defaultTtl: 3600); // or a default TTL in seconds

$cache->set('user.42', $user, 300);   // 5-minute TTL
$user = $cache->get('user.42');       // null on miss
$user = $cache->get('user.42', $fallback);

$cache->has('user.42');
$cache->delete('user.42');

$cache->setMultiple(['a' => 1, 'b' => 2], new DateInterval('PT10M'));
$values = $cache->getMultiple(['a', 'b', 'missing'], $default);
$cache->deleteMultiple(['a', 'b']);
```

Entries are namespaced under `psr16:`.

## PSR-6 usage

```php
use Ephpm\Cache\Psr6\CachePool;

$pool = new CachePool();

$item = $pool->getItem('report.monthly');
if (!$item->isHit()) {
    $item->set(buildReport())->expiresAfter(3600);
    $pool->save($item);
}
$report = $item->get();

// Deferred writes, flushed together on commit():
$pool->saveDeferred($pool->getItem('a')->set(1));
$pool->saveDeferred($pool->getItem('b')->set(2));
$pool->commit();
```

Entries are namespaced under `psr6:`, disjoint from the PSR-16 cache.

## Important: `clear()` is intentionally unsupported

Both `Psr16\Cache::clear()` and `Psr6\CachePool::clear()` **throw
`Ephpm\Cache\Exception\UnsupportedOperationException`**.

The ePHPm SAPI exposes only `ephpm_kv_flush_all()`, which wipes the **entire
shared store** -- that includes your PHP sessions and every other namespace, not
just this cache. Silently calling it to satisfy a per-namespace `clear()` would
be a data-loss footgun, so we refuse.

To evict cache entries, either:

- delete specific keys (`delete()` / `deleteItem()` / `deleteMultiple()`), or
- give entries a TTL so they self-evict.

A future ePHPm SAPI that exposes a prefix-scan or key index would let us
implement a scoped `clear()`; until then, honesty beats a destructive surprise.

## Framework configuration

### Symfony

Register the pool as a service and point a cache pool adapter at it:

```yaml
# config/services.yaml
services:
    Ephpm\Cache\Psr6\CachePool: ~

# config/packages/cache.yaml
framework:
    cache:
        pools:
            app.cache:
                adapter: Ephpm\Cache\Psr6\CachePool
```

Note: Symfony's `cache:pool:clear` will surface the
`UnsupportedOperationException`. Prefer explicit key deletion or TTLs.

### Laravel

Laravel's cache uses its own `Repository`, but any PSR-16 consumer can take the
cache directly:

```php
// A service provider binding
$this->app->singleton(\Psr\SimpleCache\CacheInterface::class, function () {
    return new \Ephpm\Cache\Psr16\Cache(defaultTtl: 600);
});
```

### Any PSR-consuming library

```php
$psr16 = new \Ephpm\Cache\Psr16\Cache();
$psr6  = new \Ephpm\Cache\Psr6\CachePool();
// hand either to whatever expects Psr\SimpleCache\CacheInterface or
// Psr\Cache\CacheItemPoolInterface.
```

## Testing

```bash
composer install
vendor/bin/phpunit
```

The test suite ships in-memory fakes for the `ephpm_kv_*` functions (see
`tests/bootstrap.php`), so the full cache logic can be exercised on a stock PHP
CLI without an ePHPm binary.

## License

MIT -- see [LICENSE](LICENSE).
