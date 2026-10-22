<?php

declare(strict_types=1);

namespace Ephpm\Cache\Psr6;

use Ephpm\Cache\KvBackend;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * PSR-6 cache pool backed by ePHPm's embedded KV store.
 *
 * Entries live under the `psr6:` namespace, disjoint from the PSR-16 cache and
 * every other tenant of the shared store.
 *
 * Deferred saves are held in {@see $deferred} and flushed to the backend on
 * {@see commit()} (or when the pool is destroyed).
 */
final class CachePool implements CacheItemPoolInterface
{
    private readonly KvBackend $backend;

    /**
     * Items queued by {@see saveDeferred()}, keyed by caller key.
     *
     * @var array<string, CacheItem>
     */
    private array $deferred = [];

    public function __construct(?KvBackend $backend = null)
    {
        $this->backend = $backend ?? new KvBackend('psr6:');
    }

    /**
     * Best-effort flush of any still-deferred items when the pool goes away.
     */
    public function __destruct()
    {
        if ($this->deferred !== []) {
            $this->commit();
        }
    }

    public function getItem(string $key): CacheItem
    {
        $this->backend->validateKey($key);

        // A deferred-but-not-yet-committed item takes precedence over the store.
        // It is logically part of the pool, so it reads back as a hit — unless
        // its expiry has already lapsed, in which case it is a miss (and will be
        // dropped rather than persisted at commit time).
        if (isset($this->deferred[$key])) {
            if ($this->isExpired($this->deferred[$key])) {
                return new CacheItem($key, null, false);
            }

            return $this->deferred[$key]->withHitState(true);
        }

        $sentinel = self::miss();
        $value = $this->backend->get($key, $sentinel);

        if ($value === $sentinel) {
            return new CacheItem($key, null, false);
        }

        return new CacheItem($key, $value, true);
    }

    /**
     * @param array<int, string> $keys
     *
     * @return iterable<string, CacheItem>
     */
    public function getItems(array $keys = []): iterable
    {
        // Validate every key eagerly (before yielding anything)...
        foreach ($keys as $key) {
            $this->backend->validateKey($key);
        }

        // ...then yield through a generator so a numeric-string key like '123'
        // is preserved as a string instead of being coerced to an int key.
        return (function () use ($keys): \Generator {
            // Keys with a pending deferred item are answered from it (see
            // getItem()); the rest are read from the store in one batch — a
            // single ephpm_kv_mget() on ePHPm v0.13.0+, per key otherwise.
            $fromStore = [];
            foreach ($keys as $key) {
                if (!isset($this->deferred[$key])) {
                    $fromStore[] = $key;
                }
            }

            $sentinel = self::miss();
            $stored = [];
            foreach ($this->backend->getMany($fromStore, $sentinel) as $i => $value) {
                $stored[$fromStore[$i]] = $value;
            }

            foreach ($keys as $key) {
                if (!\array_key_exists($key, $stored)) {
                    yield $key => $this->getItem($key);

                    continue;
                }

                $value = $stored[$key];

                yield $key => $value === $sentinel
                    ? new CacheItem($key, null, false)
                    : new CacheItem($key, $value, true);
            }
        })();
    }

    public function hasItem(string $key): bool
    {
        $this->backend->validateKey($key);

        if (isset($this->deferred[$key])) {
            // A deferred item counts as present only while it is still live; an
            // expired deferred item is not "in" the cache.
            return !$this->isExpired($this->deferred[$key]);
        }

        return $this->backend->has($key);
    }

    /**
     * Clearing a single namespace is not supported by the ePHPm SAPI.
     *
     * The only primitive available is `ephpm_kv_flush_all()`, which would wipe
     * the *entire* shared store (sessions and every other namespace), not just
     * this PSR-6 pool. Rather than throw -- which breaks PSR-6 consumers and the
     * compliance suite, both of which expect a `bool` -- this is a no-op that
     * returns `false` and emits an `E_USER_WARNING` so the failure is visible.
     *
     * Deferred-but-uncommitted items *are* dropped, since those are private to
     * this pool instance and clearing them is safe.
     *
     * To evict entries, delete specific items ({@see deleteItem()} /
     * {@see deleteItems()}) or give them a TTL via `expiresAfter()`.
     */
    public function clear(): bool
    {
        $this->deferred = [];

        \trigger_error(
            'Ephpm\Cache\Psr6\CachePool::clear() is a no-op: the ePHPm SAPI only '
            . 'offers ephpm_kv_flush_all(), which would wipe the entire shared '
            . 'store (sessions and every other namespace), not just this PSR-6 '
            . 'pool. Delete specific items instead, or use expiresAfter() so '
            . 'entries self-evict.',
            \E_USER_WARNING,
        );

        return false;
    }

    public function deleteItem(string $key): bool
    {
        $this->backend->validateKey($key);
        unset($this->deferred[$key]);

        return $this->backend->delete($key);
    }

    /**
     * @param array<int, string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        // PSR-6 requires every key to be validated before anything is mutated,
        // so a single malformed key cannot leave a partial deletion behind.
        foreach ($keys as $key) {
            $this->backend->validateKey($key);
        }

        $ok = true;

        foreach ($keys as $key) {
            $ok = $this->deleteItem($key) && $ok;
        }

        return $ok;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof CacheItem) {
            // We can only introspect our own item type for TTL metadata.
            return $this->backend->set($item->getKey(), $item->get());
        }

        $ttl = $item->ttlSeconds();

        // An item whose expiry is already in the past must not be stored.
        if ($ttl !== null && $ttl <= 0) {
            return $this->backend->delete($item->getKey());
        }

        unset($this->deferred[$item->getKey()]);

        return $this->backend->set($item->getKey(), $item->rawValue(), $ttl);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (!$item instanceof CacheItem) {
            return $this->save($item);
        }

        $this->deferred[$item->getKey()] = $item;

        return true;
    }

    /**
     * Persist every deferred item.
     *
     * Items are grouped by remaining TTL and each group is written with one
     * {@see KvBackend::setMany()} call — a single `ephpm_kv_mset()` per group on
     * ePHPm v0.13.0+, one `ephpm_kv_set()` per item on older runtimes. Items
     * whose expiry has already lapsed are deleted rather than stored, exactly
     * as {@see save()} does.
     */
    public function commit(): bool
    {
        $ok = true;

        /** @var array<int, list<array{0: string, 1: mixed}>> $groups TTL seconds (0 = none) => entries */
        $groups = [];

        foreach ($this->deferred as $item) {
            $ttl = $item->ttlSeconds();

            if ($ttl !== null && $ttl <= 0) {
                $ok = $this->backend->delete($item->getKey()) && $ok;

                continue;
            }

            $groups[$ttl ?? 0][] = [$item->getKey(), $item->rawValue()];
        }

        $this->deferred = [];

        foreach ($groups as $ttl => $entries) {
            $ok = $this->backend->setMany($entries, $ttl === 0 ? null : $ttl) && $ok;
        }

        return $ok;
    }

    /**
     * Whether a deferred item's expiry has already lapsed.
     *
     * A null TTL means "no expiry"; a non-positive TTL means the expiry moment
     * is now or in the past.
     */
    private function isExpired(CacheItem $item): bool
    {
        $ttl = $item->ttlSeconds();

        return $ttl !== null && $ttl <= 0;
    }

    /**
     * Per-process unique miss sentinel (a stored `null` must still read as hit).
     */
    private static function miss(): object
    {
        static $sentinel = null;

        return $sentinel ??= new \stdClass();
    }
}
