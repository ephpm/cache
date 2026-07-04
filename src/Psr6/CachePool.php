<?php

declare(strict_types=1);

namespace Ephpm\Cache\Psr6;

use Ephpm\Cache\Exception\UnsupportedOperationException;
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
        if (isset($this->deferred[$key])) {
            return clone $this->deferred[$key];
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
        $items = [];

        foreach ($keys as $key) {
            $items[$key] = $this->getItem($key);
        }

        return $items;
    }

    public function hasItem(string $key): bool
    {
        $this->backend->validateKey($key);

        if (isset($this->deferred[$key])) {
            return true;
        }

        return $this->backend->has($key);
    }

    /**
     * @throws UnsupportedOperationException always -- see the README. The SAPI
     *         exposes no per-namespace clear, only a global flush that would
     *         destroy sessions and every other namespace.
     */
    public function clear(): bool
    {
        throw new UnsupportedOperationException(
            'Ephpm\Cache\Psr6\CachePool::clear() is not supported. The ePHPm '
            . 'SAPI only offers ephpm_kv_flush_all(), which would wipe the '
            . 'entire shared store (sessions and every other namespace), not '
            . 'just this PSR-6 pool. Delete specific items instead, or use '
            . 'expiresAfter() so entries self-evict.',
        );
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

    public function commit(): bool
    {
        $ok = true;

        foreach ($this->deferred as $item) {
            $ok = $this->save($item) && $ok;
        }

        $this->deferred = [];

        return $ok;
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
