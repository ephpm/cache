<?php

declare(strict_types=1);

namespace Ephpm\Cache;

use DateInterval;
use DateTimeImmutable;
use Ephpm\Cache\Exception\InvalidArgumentException;

/**
 * Shared internal adapter over ePHPm's global `ephpm_kv_*` SAPI functions.
 *
 * Both the PSR-16 and PSR-6 facades delegate to a single instance of this
 * class. It owns every concern that is common to the two standards:
 *
 *  - guarding that the SAPI functions actually exist (i.e. we are running
 *    inside an ePHPm binary, in either FPM-style or worker mode);
 *  - namespacing keys with a per-facade prefix so PSR-16 and PSR-6 entries
 *    never collide;
 *  - validating keys against the PSR reserved character set;
 *  - serialising arbitrary PHP values to strings and back, since the KV store
 *    is a plain string store;
 *  - normalising the many accepted TTL shapes (null | int seconds |
 *    {@see DateInterval}) into an integer number of seconds.
 *
 * The KV store underneath is in-process (no network hop) and, in clustered
 * deployments, gossip-replicated across nodes.
 */
final class KvBackend
{
    /**
     * Characters reserved by PSR-16 and PSR-6 that must not appear in a key.
     *
     * @see https://www.php-fig.org/psr/psr-16/#12-definitions
     */
    private const RESERVED_CHARACTERS = '{}()/\\@:';

    /**
     * @param non-empty-string $prefix Namespace prepended to every key handed
     *                                 to the KV store (e.g. `psr16:`).
     */
    public function __construct(
        private readonly string $prefix,
    ) {
        $this->assertAvailable();
    }

    /**
     * Verify the ephpm_kv_* SAPI surface is present.
     *
     * @throws \RuntimeException when not running under an ePHPm SAPI.
     */
    public function assertAvailable(): void
    {
        if (!\function_exists('ephpm_kv_get')) {
            throw new \RuntimeException(
                'ephpm/cache requires the ePHPm SAPI: the global ephpm_kv_* '
                . 'functions are unavailable. This package only works when the '
                . 'application is served by an ePHPm binary (FPM-style or '
                . 'worker mode).',
            );
        }
    }

    /**
     * Validate a cache key against the PSR rules and return it unchanged.
     *
     * @throws InvalidArgumentException when the key is empty or contains a
     *                                  reserved character.
     */
    public function validateKey(mixed $key): string
    {
        if (!\is_string($key)) {
            throw new InvalidArgumentException(\sprintf(
                'Cache key must be a string, %s given.',
                \get_debug_type($key),
            ));
        }

        if ($key === '') {
            throw new InvalidArgumentException('Cache key must not be empty.');
        }

        if (\strpbrk($key, self::RESERVED_CHARACTERS) !== false) {
            throw new InvalidArgumentException(\sprintf(
                'Cache key "%s" contains one or more reserved characters (%s).',
                $key,
                self::RESERVED_CHARACTERS,
            ));
        }

        return $key;
    }

    /**
     * Map an unvalidated caller key onto the fully namespaced storage key.
     */
    public function storageKey(string $key): string
    {
        return $this->prefix . $this->validateKey($key);
    }

    /**
     * Fetch a value, returning the sentinel `$missing` on a cache miss.
     *
     * A distinct sentinel is required (rather than plain null) because a stored
     * value of `null` is a legitimate hit and must be distinguishable from an
     * absent key.
     *
     * @template T
     *
     * @param T $missing
     *
     * @return mixed|T
     */
    public function get(string $key, mixed $missing = null): mixed
    {
        $raw = ephpm_kv_get($this->storageKey($key));

        if ($raw === null) {
            return $missing;
        }

        return $this->unserialize($raw);
    }

    /**
     * Store a value with an optional TTL.
     *
     * @param null|int|DateInterval $ttl null / non-positive means "no expiry".
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $seconds = $this->normalizeTtl($ttl);

        // A zero/negative TTL that came from an already-expired DateInterval
        // should be treated as an immediate delete, mirroring PSR semantics.
        if ($seconds !== null && $seconds <= 0) {
            $this->delete($key);

            return true;
        }

        return ephpm_kv_set(
            $this->storageKey($key),
            $this->serialize($value),
            $seconds ?? 0,
        );
    }

    /**
     * Delete a key. Returns true whether or not the key existed, matching the
     * PSR contract that delete succeeds unless an error occurs.
     */
    public function delete(string $key): bool
    {
        ephpm_kv_del($this->storageKey($key));

        return true;
    }

    /**
     * Report whether a key currently exists (and has not expired).
     */
    public function has(string $key): bool
    {
        return ephpm_kv_exists($this->storageKey($key));
    }

    /**
     * Remaining time-to-live in seconds, or null if the key has no expiry or is
     * absent. Used by the PSR-6 pool to reconstruct item expiry metadata.
     */
    public function ttlSeconds(string $key): ?int
    {
        $ttl = ephpm_kv_ttl($this->storageKey($key));

        // -1 = key exists but never expires; -2 = key does not exist.
        if ($ttl < 0) {
            return null;
        }

        return $ttl;
    }

    /**
     * Serialise a PHP value for storage.
     *
     * `serialize()` is used (rather than JSON) so that objects, closures'
     * host graphs, and precise scalar types survive a round trip intact.
     */
    public function serialize(mixed $value): string
    {
        return \serialize($value);
    }

    /**
     * Reverse of {@see serialize()}.
     */
    public function unserialize(string $raw): mixed
    {
        return \unserialize($raw);
    }

    /**
     * Collapse the accepted TTL shapes into a signed integer of seconds.
     *
     * @return int|null null means "store without expiry".
     */
    public function normalizeTtl(null|int|DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if (\is_int($ttl)) {
            return $ttl;
        }

        // DateInterval carries no absolute duration on its own (months/years
        // are calendar-relative), so anchor it to "now" and diff.
        $now = new DateTimeImmutable();
        $expiry = $now->add($ttl);

        return $expiry->getTimestamp() - $now->getTimestamp();
    }
}
