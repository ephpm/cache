<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * ePHPm's `ephpm_kv_*` functions only exist inside the ePHPm SAPI, so when the
 * suite runs under a stock PHP CLI we polyfill them with a process-local
 * in-memory array. This lets every branch of the cache logic be exercised
 * offline while remaining behaviourally faithful to the real SAPI:
 *
 *  - get() returns null on miss;
 *  - set()'s TTL of 0 means "no expiry";
 *  - ttl() returns -1 (exists, no expiry) / -2 (missing);
 *  - flush_all() clears the entire shared store.
 *
 * If the real SAPI functions are already present (i.e. the suite is somehow
 * run inside ePHPm) we defer to them and define nothing.
 */

require_once __DIR__ . '/../vendor/autoload.php';

if (!function_exists('ephpm_kv_get')) {

    /**
     * Backing store shared by all fake functions.
     *
     * Shape: key => ['value' => string, 'expires' => int|null].
     * `expires` is an absolute UNIX timestamp, or null for no expiry.
     */
    final class EphpmKvFake
    {
        /** @var array<string, array{value: string, expires: int|null}> */
        public static array $store = [];

        public static function reset(): void
        {
            self::$store = [];
        }

        public static function alive(string $key): bool
        {
            if (!isset(self::$store[$key])) {
                return false;
            }

            $expires = self::$store[$key]['expires'];

            if ($expires !== null && $expires <= time()) {
                unset(self::$store[$key]);

                return false;
            }

            return true;
        }
    }

    function ephpm_kv_get(string $key): ?string
    {
        return EphpmKvFake::alive($key) ? EphpmKvFake::$store[$key]['value'] : null;
    }

    function ephpm_kv_set(string $key, string $value, int $ttlSeconds = 0): bool
    {
        EphpmKvFake::$store[$key] = [
            'value' => $value,
            'expires' => $ttlSeconds > 0 ? time() + $ttlSeconds : null,
        ];

        return true;
    }

    function ephpm_kv_setnx(string $key, string $value, int $ttlSeconds = 0): bool
    {
        if (EphpmKvFake::alive($key)) {
            return false;
        }

        return ephpm_kv_set($key, $value, $ttlSeconds);
    }

    function ephpm_kv_del(string $key): int
    {
        if (isset(EphpmKvFake::$store[$key])) {
            unset(EphpmKvFake::$store[$key]);

            return 1;
        }

        return 0;
    }

    function ephpm_kv_exists(string $key): bool
    {
        return EphpmKvFake::alive($key);
    }

    function ephpm_kv_incr(string $key): int|false
    {
        return ephpm_kv_incr_by($key, 1);
    }

    function ephpm_kv_decr(string $key): int|false
    {
        return ephpm_kv_incr_by($key, -1);
    }

    function ephpm_kv_incr_by(string $key, int $delta): int|false
    {
        $current = EphpmKvFake::alive($key) ? EphpmKvFake::$store[$key]['value'] : '0';

        if (!is_numeric($current)) {
            return false;
        }

        $next = (int) $current + $delta;
        $expires = EphpmKvFake::$store[$key]['expires'] ?? null;
        EphpmKvFake::$store[$key] = ['value' => (string) $next, 'expires' => $expires];

        return $next;
    }

    function ephpm_kv_expire(string $key, int $ttlSeconds): bool
    {
        if (!EphpmKvFake::alive($key)) {
            return false;
        }

        EphpmKvFake::$store[$key]['expires'] = $ttlSeconds > 0 ? time() + $ttlSeconds : null;

        return true;
    }

    function ephpm_kv_ttl(string $key): int
    {
        if (!EphpmKvFake::alive($key)) {
            return -2;
        }

        $expires = EphpmKvFake::$store[$key]['expires'];

        return $expires === null ? -1 : max(0, $expires - time());
    }

    function ephpm_kv_pttl(string $key): int
    {
        $ttl = ephpm_kv_ttl($key);

        return $ttl < 0 ? $ttl : $ttl * 1000;
    }

    function ephpm_kv_flush_all(): bool
    {
        EphpmKvFake::reset();

        return true;
    }
}
