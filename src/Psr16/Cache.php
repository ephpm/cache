<?php

declare(strict_types=1);

namespace Ephpm\Cache\Psr16;

use DateInterval;
use Ephpm\Cache\Exception\UnsupportedOperationException;
use Ephpm\Cache\KvBackend;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 (SimpleCache) implementation backed by ePHPm's embedded KV store.
 *
 * Entries live under the `psr16:` namespace so they never collide with the
 * PSR-6 pool, sessions, or any other tenant of the shared store.
 *
 * @see \Ephpm\Cache\Psr6\CachePool for the PSR-6 counterpart.
 */
final class Cache implements CacheInterface
{
    private readonly KvBackend $backend;

    /**
     * Unique sentinel used internally to distinguish a cache miss from a stored
     * `null`. Never leaks to callers.
     */
    private readonly object $miss;

    /**
     * @param null|int|DateInterval $defaultTtl Applied when {@see set()} /
     *        {@see setMultiple()} are called with `$ttl === null`. Null here
     *        means "store without expiry by default".
     */
    public function __construct(
        private readonly null|int|DateInterval $defaultTtl = null,
        ?KvBackend $backend = null,
    ) {
        $this->backend = $backend ?? new KvBackend('psr16:');
        $this->miss = new \stdClass();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->backend->get($key, $this->miss);

        return $value === $this->miss ? $default : $value;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        return $this->backend->set($key, $value, $ttl ?? $this->defaultTtl);
    }

    public function delete(string $key): bool
    {
        return $this->backend->delete($key);
    }

    /**
     * @throws UnsupportedOperationException always -- see the class docs and
     *         README. Clearing a single namespace would require a key index the
     *         SAPI does not expose; the only primitive available
     *         (`ephpm_kv_flush_all()`) would nuke the entire shared store.
     */
    public function clear(): bool
    {
        throw new UnsupportedOperationException(
            'Ephpm\Cache\Psr16\Cache::clear() is not supported. The ePHPm SAPI '
            . 'only offers ephpm_kv_flush_all(), which would wipe the entire '
            . 'shared store (sessions and every other namespace), not just this '
            . 'PSR-16 namespace. Delete specific keys instead, or use a short '
            . 'TTL so entries self-evict.',
        );
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];

        foreach ($keys as $key) {
            // validateKey (via get) enforces string/reserved-char rules.
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $ok = true;

        foreach ($values as $key => $value) {
            // PSR-16 keys are strings; an integer-like key arriving from an
            // array cast must be re-stringified before validation.
            $ok = $this->set((string) $key, $value, $ttl) && $ok;
        }

        return $ok;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;

        foreach ($keys as $key) {
            $ok = $this->delete($key) && $ok;
        }

        return $ok;
    }

    public function has(string $key): bool
    {
        // Route through the backend so the same key validation applies.
        $this->backend->validateKey($key);

        return $this->backend->has($key);
    }
}
