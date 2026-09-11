<?php

/**
 * @internal IDE stub — do not include at runtime.
 *
 * These declarations describe the native global functions that the ePHPm
 * engine registers for its embedded key-value store (available in both
 * FPM-style and worker request modes). This file exists PURELY for IDEs and
 * static analysers (PhpStorm, Psalm, PHPStan). It is never autoloaded and must
 * never be `require`d at runtime — doing so would redefine the native symbols
 * and cause a fatal error.
 *
 * Point your analyzer at the `stubs/` directory to pick these up.
 *
 * Semantics (authoritative source: crates/ephpm-php/ephpm_wrapper.c — the KV
 * SAPI functions — and crates/ephpm-kv/src/command.rs in
 * github.com/ephpm/ephpm):
 *
 * - The effective store is the **per-site** store when the request is bound to
 *   a virtual host, otherwise the **global** store; keys never cross that
 *   boundary. In a clustered deployment writes gossip-replicate across nodes.
 * - Values are opaque byte strings (binary-safe). TTLs are in **seconds** at
 *   this boundary (0 = no expiry); `ephpm_kv_pttl()` reports the remaining
 *   time in **milliseconds**.
 * - The counter functions operate on the value's decimal-integer text form;
 *   they return `false` when the stored value is not an integer (a blind
 *   `(int)` cast of that `false` would silently read as 0 — distinguish it).
 * - Writes can be refused when the store is at `maxmemory` under a no-eviction
 *   policy: `ephpm_kv_set()` / `ephpm_kv_setnx()` then return `false` without
 *   storing. On the `setnx` path that `false` is indistinguishable from
 *   "a live entry already exists".
 */

declare(strict_types=1);

/**
 * Get a value by key.
 *
 * @return string|null the stored value, or null when the key does not exist
 *                     (or has expired)
 */
function ephpm_kv_get(string $key): ?string
{
}

/**
 * Set a key to a value, with an optional TTL.
 *
 * @param int $ttl expiry in **seconds**; 0 (the default) or negative means no
 *                 expiry
 *
 * @return bool true on success; false if the store refused the write (OOM
 *              under a no-eviction policy) or no KV store is registered
 */
function ephpm_kv_set(string $key, string $value, int $ttl = 0): bool
{
}

/**
 * Atomically set a key only if it does not already exist (the Redis `SETNX` /
 * `SET … NX` primitive). The check-and-set is atomic under the store's
 * per-shard lock — the primitive PHP lock libraries build on.
 *
 * @param int $ttl expiry in **seconds**; 0 (the default) or negative means no
 *                 expiry
 *
 * @return bool true if the value was inserted; false if a live entry already
 *              existed OR the store refused the write (OOM). The two outcomes
 *              are not distinguishable on this path.
 */
function ephpm_kv_setnx(string $key, string $value, int $ttl = 0): bool
{
}

/**
 * Delete a key.
 *
 * @return int 1 if the key existed and was removed, 0 if it did not exist
 */
function ephpm_kv_del(string $key): int
{
}

/**
 * Whether a key currently exists (and has not expired).
 */
function ephpm_kv_exists(string $key): bool
{
}

/**
 * Atomically increment the integer at `$key` by 1, returning the new value.
 * Creates the key at 0 first when it is absent.
 *
 * @return int|false the new value, or false when the stored value is not an
 *                   integer (or the create would exceed the memory budget)
 */
function ephpm_kv_incr(string $key): int|false
{
}

/**
 * Atomically decrement the integer at `$key` by 1, returning the new value.
 * Creates the key at 0 first when it is absent.
 *
 * @return int|false the new value, or false when the stored value is not an
 *                   integer (or the create would exceed the memory budget)
 */
function ephpm_kv_decr(string $key): int|false
{
}

/**
 * Atomically add `$delta` (which may be negative) to the integer at `$key`,
 * returning the new value. Creates the key at 0 first when it is absent.
 *
 * @return int|false the new value, or false when the stored value is not an
 *                   integer (or the create would exceed the memory budget)
 */
function ephpm_kv_incr_by(string $key, int $delta): int|false
{
}

/**
 * Set (or refresh) the TTL on an existing key.
 *
 * @param int $ttl expiry in **seconds**; 0 or negative clears the deadline
 *
 * @return bool true if the key existed and the TTL was applied, false if the
 *              key was missing
 */
function ephpm_kv_expire(string $key, int $ttl): bool
{
}

/**
 * Remaining time-to-live in **seconds** (rounded up, so 1..999 ms reads as 1).
 *
 * @return int the seconds remaining, -1 if the key exists with no expiry, or
 *             -2 if the key does not exist
 */
function ephpm_kv_ttl(string $key): int
{
}

/**
 * Remaining time-to-live in **milliseconds** (the Redis `PTTL` form).
 *
 * @return int the milliseconds remaining, -1 if the key exists with no expiry,
 *             or -2 if the key does not exist
 */
function ephpm_kv_pttl(string $key): int
{
}

/**
 * Remove every key from the effective store (the per-site store when the
 * request is bound to a site, otherwise the global store) — the Redis
 * `FLUSHDB` / `FLUSHALL` primitive.
 *
 * Wipes the WHOLE effective store, including PHP sessions and every other
 * namespace sharing it — not scoped to any key prefix.
 *
 * @return bool true on success, false if no KV store is registered
 */
function ephpm_kv_flush_all(): bool
{
}

/**
 * Block until `$key`'s watch version exceeds `$last_version`, or `$timeout_ms`
 * elapses — the building block for SSE/long-poll fan-out over the KV store.
 *
 * The per-key version is monotonic for the process lifetime and only advances
 * for writes made AFTER the first wait on that key, so always seed the protocol
 * with `$last_version = 0`: that first call registers the watch and returns the
 * current value and version immediately (a race-free snapshot). Negative
 * arguments are treated as 0; `$timeout_ms = 0` is a non-blocking poll. Watches
 * observe string keys only (set/setnx/del/incr/decr/incr_by/append/expiry-reap/
 * flush bump the version).
 *
 * @return array{version: int, value: string|null}|false the new version and
 *                     value (`value` is null when the key was deleted or has
 *                     expired) on a change, or false on timeout
 */
function ephpm_kv_wait(string $key, int $last_version, int $timeout_ms): array|false
{
}
