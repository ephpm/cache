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
  works (the `ephpm_kv_*` functions have shipped since ePHPm v0.1.0, and
  `ephpm_kv_flush_all()` since v0.1.2; current release: v0.10.2). The functions
  are provided by the SAPI and are available in both FPM-style and worker
  (long-lived) request modes. Outside ePHPm the constructors throw a
  `RuntimeException`.
- **ePHPm v0.13.0+ is faster, not required.** On v0.13.0+ the multi-key
  operations use the batched `ephpm_kv_mget()` / `ephpm_kv_mset()` (see
  [Batched multi-key operations](#batched-multi-key-operations)). Each is
  feature-detected with `function_exists()`; on older ePHPm the package uses
  the per-key path exactly as before.

## Installation

ePHPm packages are distributed via their GitHub repositories, not Packagist.
Add this repo as a Composer `vcs` repository, then require the package
(`ephpm/cache` is tagged `v0.2.0`, so `^0.2` resolves):

```bash
composer config repositories.ephpm/cache vcs https://github.com/ephpm/cache
composer require ephpm/cache:^0.2
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

## Batched multi-key operations

On ePHPm **v0.13.0+** the multi-key calls go to the store in one SAPI call
instead of one per key:

| Call | ePHPm v0.13.0+ | Older ePHPm |
|------|----------------|-------------|
| PSR-16 `getMultiple()` | one `ephpm_kv_mget()` | `ephpm_kv_get()` per key |
| PSR-16 `setMultiple()` | one `ephpm_kv_mset()` (every entry shares the call's TTL) | `ephpm_kv_set()` per key |
| PSR-6 `getItems()` | one `ephpm_kv_mget()` for the keys with no pending deferred item | `ephpm_kv_get()` per key |
| PSR-6 `commit()` | one `ephpm_kv_mset()` per distinct remaining TTL | `ephpm_kv_set()` per item |
| `deleteMultiple()` / `deleteItems()` | `ephpm_kv_del()` per key (no multi-key delete exists) | same |

Values are serialized exactly as for the single-key calls, and TTL handling is
unchanged (an already-expired TTL still deletes instead of storing).
`setMultiple()` now validates every key before writing anything, so an invalid
key no longer leaves the keys before it written. If `ephpm_kv_mget()` reports
a failure, the read falls back to the per-key path. `ephpm_kv_mset()` is not
atomic: if the store refuses a write part-way (memory limit) it returns
`false` with the earlier keys already stored, and `setMultiple()`/`commit()`
return `false`, the same outcome as a refused per-key write.

To force the per-key path (e.g. while comparing behaviour), construct the
backend yourself: `new Cache(null, new KvBackend('psr16:', batchFunctions: false))`
or `new CachePool(new KvBackend('psr6:', batchFunctions: false))`.

## Important: `clear()` is intentionally a no-op

Both `Psr16\Cache::clear()` and `Psr6\CachePool::clear()` **do nothing, return
`false`, and emit an `E_USER_WARNING`**. (PSR-6's `clear()` additionally drops
this pool instance's own not-yet-committed deferred items, which are private to
it and safe to discard.)

The ePHPm SAPI exposes only `ephpm_kv_flush_all()`, which wipes the **entire
shared store** -- that includes your PHP sessions and every other namespace, not
just this cache. Silently calling it to satisfy a per-namespace `clear()` would
be a data-loss footgun, so we refuse to.

Returning `false` (rather than throwing) keeps the methods within their PSR
`: bool` contract, so generic PSR-6/PSR-16 consumers — and the official
`cache/integration-tests` compliance suite — don't blow up on a call they treat
as routine; the warning makes the no-op visible in logs.

To evict cache entries, either:

- delete specific keys (`delete()` / `deleteItem()` / `deleteMultiple()`), or
- give entries a TTL so they self-evict.

ePHPm v0.13.0 adds `ephpm_kv_scan()`, but it is not a basis for a scoped
`clear()`: in a cluster it lists only **this node's** keyspace, and string
values larger than `[cluster.kv] small_key_threshold` live only on their
replica nodes, so a scan-and-delete would silently miss entries. Until the
SAPI offers a complete prefix delete, honesty beats a destructive (or
incomplete) surprise.

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

Note: Symfony's `cache:pool:clear` will be a no-op here (it returns `false` and
logs a warning rather than clearing). Prefer explicit key deletion or TTLs.

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
CLI without an ePHPm binary. Alongside the package's own unit tests, it runs the
official [`cache/integration-tests`](https://github.com/php-cache/integration-tests)
PSR-16 and PSR-6 compliance suites (`tests/Compliance/`) against these fakes —
twice each: once on the batched v0.13.0+ path and once with it switched off
(the per-key path older ePHPm takes). The only skipped cases are the
`clear()`-dependent ones described above, each skipped with a documented
reason.

## IDE / static analysis stubs

The `ephpm_kv_*` functions this package calls are registered natively by the
ePHPm engine, so they are invisible to IDEs and static analysers on a normal
dev machine. `stubs/ephpm-kv.stub.php` declares the whole `ephpm_kv_*` surface
with its exact signature, return type, failure value and TTL units, so
PhpStorm/Psalm/PHPStan resolve the calls and stop flagging them as undefined:

- **All ePHPm versions with KV:** `get`, `set`, `setnx`, `del`, `del_if_eq`
  (v0.11.x+), `exists`, `incr`, `decr`, `incr_by`, `expire`, `ttl`, `pttl`,
  `flush_all`, `wait`.
- **ePHPm v0.13.0+ only** (feature-detect with `function_exists()`):
  hashes — `hget`, `hset`, `hsetnx`, `hmget`, `hgetall`, `hdel`, `hexists`,
  `hlen`, `hkeys`, `hvals`, `hincr_by`, `hexpire`, `httl`, `hpttl`,
  `hpersist`; strings and keys — `mget`, `mset`, `getdel`, `getex`,
  `persist`, `set_if_eq`, `append`, `strlen`, `type`, `scan`. On v0.13.0+
  `expire`/`ttl`/`pttl` also work on hash keys and `wait` also wakes on hash
  writes.

Point your analyzer at the `stubs/` directory (PhpStorm picks it up
automatically; for Psalm/PHPStan add it to the scanned/stub paths). The stub is
**not** autoloaded and must never be `require`d at runtime — the real functions
already exist inside ePHPm, and loading the stub would redefine them and fatal.
This mirrors how [`ephpm/db`](https://github.com/ephpm/db) ships
`stubs/ephpm-db.stub.php`.

## License

MIT -- see [LICENSE](LICENSE).
