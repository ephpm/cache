<?php

declare(strict_types=1);

namespace Ephpm\Cache\Psr16;

use DateInterval;
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
     * Clearing a single namespace is not supported by the ePHPm SAPI.
     *
     * A scoped clear would require a key index the SAPI does not expose; the
     * only primitive available (`ephpm_kv_flush_all()`) would nuke the entire
     * shared store -- sessions and every other namespace included. Rather than
     * throw -- which breaks PSR-16 consumers and the compliance suite, both of
     * which expect a `bool` -- this is a no-op that returns `false` and emits an
     * `E_USER_WARNING` so the failure is visible. Delete specific keys, or give
     * entries a TTL so they self-evict.
     */
    public function clear(): bool
    {
        \trigger_error(
            'Ephpm\Cache\Psr16\Cache::clear() is a no-op: the ePHPm SAPI only '
            . 'offers ephpm_kv_flush_all(), which would wipe the entire shared '
            . 'store (sessions and every other namespace), not just this PSR-16 '
            . 'namespace. Delete specific keys instead, or use a short TTL so '
            . 'entries self-evict.',
            \E_USER_WARNING,
        );

        return false;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        // Validate every key eagerly: PSR-16 requires an invalid key to raise
        // InvalidArgumentException when getMultiple() is *called*, not lazily
        // during iteration.
        $validated = [];
        foreach ($keys as $key) {
            $this->backend->validateKey($key);
            $validated[] = $key;
        }

        // Yield through a generator so a numeric-string key like '123' is
        // preserved as a string instead of being coerced to an int array key.
        // The read is one ephpm_kv_mget() on ePHPm v0.13.0+, per key otherwise.
        return (function () use ($validated, $default): \Generator {
            $values = $this->backend->getMany($validated, $this->miss);

            foreach ($validated as $i => $key) {
                yield $key => $values[$i] === $this->miss ? $default : $values[$i];
            }
        })();
    }

    /**
     * Every key is validated before anything is written. All entries share one
     * TTL, so on ePHPm v0.13.0+ this is a single `ephpm_kv_mset()` call; older
     * runtimes write each entry with `ephpm_kv_set()`.
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $entries = [];

        foreach ($values as $key => $value) {
            $entries[] = [$this->normalizeMultiKey($key), $value];
        }

        return $this->backend->setMany($entries, $ttl ?? $this->defaultTtl);
    }

    /**
     * Normalise a `$values` key from {@see setMultiple()} to a string.
     *
     * PHP array keys are always int|string, and an int key (e.g. from
     * `['0' => ...]` or a numeric-string key PHP has already coerced) is a
     * legal PSR-16 key once stringified. Any other type -- only reachable when
     * the caller passes a Generator yielding a non-int/string key -- violates
     * the PSR-16 string-key contract and is rejected.
     *
     * @throws InvalidArgumentException when the key is neither int nor a valid
     *                                  string key.
     */
    private function normalizeMultiKey(mixed $key): string
    {
        if (\is_int($key)) {
            return (string) $key;
        }

        return $this->backend->validateKey($key);
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
