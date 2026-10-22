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
 * - Keys and hash field names must be valid UTF-8 (a NUL byte is fine). A
 *   non-UTF-8 key raises `E_WARNING` and the call returns its failure value.
 * - **Failure values.** Each function documents what it returns when no KV
 *   store is attached (the `ephpm php` CLI), the request has no KV keyspace (a
 *   multi-tenant host that matched no vhost), a key is invalid, or a write was
 *   refused (memory limit, or in a cluster an oversized hash field). A warning
 *   is emitted once for the unwired / no-keyspace cases.
 *
 * Availability: the functions in the first group (get … wait) have shipped
 * since ePHPm v0.1.0 (`ephpm_kv_flush_all()` since v0.1.2,
 * `ephpm_kv_del_if_eq()` since v0.11.x). Everything from the "ePHPm v0.13.0+"
 * marker below exists **only on ePHPm >= v0.13.0** — feature-detect each one
 * with `function_exists()` before calling it and keep a fallback.
 *
 * Cluster notes (v0.13.0+): hashes replicate per field, and a clustered hash
 * field value is limited to `[cluster.kv] small_key_threshold - 17` bytes (495
 * by default); a larger value makes the write fail. Do not store large blobs in
 * hash fields — use plain string keys for those.
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
 * Atomically delete `$key` only while its stored value still equals `$token`
 * (compare-and-delete) — the safe-release primitive a lock pairs with
 * {@see ephpm_kv_setnx()}: a holder that overran its lock TTL can no longer
 * delete the next holder's lock. In a cluster it is decided by the key's owner.
 *
 * Available since ePHPm v0.11.x — feature-detect with `function_exists()`.
 *
 * @return bool true if this call removed the key; false on a value mismatch, a
 *              missing/expired key, or failure
 */
function ephpm_kv_del_if_eq(string $key, string $token): bool
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
 * Set (or refresh) the whole-key TTL on an existing key.
 *
 * Since ePHPm v0.13.0 this also works on **hash** keys (whole-key TTL; per-field
 * TTLs are {@see ephpm_kv_hexpire()}); older versions only see string keys.
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
 * Since ePHPm v0.13.0 this also reports the whole-key TTL of **hash** keys.
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
 * Since ePHPm v0.13.0 this also reports the whole-key TTL of **hash** keys.
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
 * arguments are treated as 0; `$timeout_ms = 0` is a non-blocking poll. String
 * writes (set/setnx/del/incr/decr/incr_by/append/expiry-reap/flush) bump the
 * version. Since ePHPm v0.13.0 **hash writes to the key also wake the wait**;
 * the returned `value` is then null, since a hash has no string value (read it
 * with {@see ephpm_kv_hgetall()}).
 *
 * @return array{version: int, value: string|null}|false the new version and
 *                     value (`value` is null when the key was deleted, has
 *                     expired, or is a hash) on a change, or false on timeout
 */
function ephpm_kv_wait(string $key, int $last_version, int $timeout_ms): array|false
{
}

// ─────────────────────────────────────────────────────────────────────────────
// ePHPm v0.13.0+ — these functions do NOT exist on older ePHPm. Feature-detect
// each one with function_exists() and keep a fallback path.
// ─────────────────────────────────────────────────────────────────────────────

// ── Hashes ──────────────────────────────────────────────────────────────────

/**
 * Get one field of a hash.
 *
 * @return string|null the field's value, or null if the hash or field does not
 *                     exist (or on failure)
 */
function ephpm_kv_hget(string $key, string $field): ?string
{
}

/**
 * Set hash fields.
 *
 * Form 1: `ephpm_kv_hset($key, $field, $value)` returns 1 if the field was
 * created, 0 if it was overwritten. Form 2: `ephpm_kv_hset($key, [$field =>
 * $value, ...])` returns the number of fields created. Array values are
 * converted to string. Writing a field clears that field's TTL.
 *
 * Form 1 with `$value` omitted or null raises `E_WARNING` and returns false.
 * In a cluster a field value larger than `[cluster.kv] small_key_threshold - 17`
 * bytes (495 by default) makes the write fail.
 *
 * @param string|array<string, string> $field a field name, or a field => value map
 * @param string|null                  $value the value for Form 1; omit for Form 2
 *
 * @return int|false fields created; false on failure (memory limit, clustered
 *                   value too large, bad key, no store)
 */
function ephpm_kv_hset(string $key, string|array $field, ?string $value = null): int|false
{
}

/**
 * Set a hash field only if it does not already exist.
 *
 * @return bool true if set; false if the field already existed (or on failure)
 */
function ephpm_kv_hsetnx(string $key, string $field, string $value): bool
{
}

/**
 * Get several fields of a hash.
 *
 * @param list<string> $fields
 *
 * @return list<string|null>|false a LIST aligned with `$fields` (same order,
 *                                 same count), null for each missing field; a
 *                                 missing hash gives a list of nulls; false
 *                                 only on failure
 */
function ephpm_kv_hmget(string $key, array $fields): array|false
{
}

/**
 * Get every field of a hash.
 *
 * Numeric-string field names become int array keys, as with any PHP array.
 *
 * @return array<array-key, string>|false field => value; [] for a missing
 *                                        hash; false only on failure
 */
function ephpm_kv_hgetall(string $key): array|false
{
}

/**
 * Delete one or more hash fields.
 *
 * @param string|list<string> $fields
 *
 * @return int the number of fields removed (0 on failure)
 */
function ephpm_kv_hdel(string $key, string|array $fields): int
{
}

/**
 * Whether a hash field exists.
 */
function ephpm_kv_hexists(string $key, string $field): bool
{
}

/**
 * Number of fields in a hash.
 *
 * @return int 0 for a missing hash, or on failure
 */
function ephpm_kv_hlen(string $key): int
{
}

/**
 * The field names of a hash.
 *
 * @return list<string>|false
 */
function ephpm_kv_hkeys(string $key): array|false
{
}

/**
 * The values of a hash.
 *
 * @return list<string>|false
 */
function ephpm_kv_hvals(string $key): array|false
{
}

/**
 * Atomically add `$delta` to the integer in a hash field (created at 0 when
 * absent). Keeps the field's TTL.
 *
 * @return int|false the new value; false if the field holds a non-integer, on
 *                   overflow, or on failure
 */
function ephpm_kv_hincr_by(string $key, string $field, int $delta = 1): int|false
{
}

/**
 * Give each named field a TTL. Missing fields are skipped.
 *
 * @param string|list<string> $fields
 * @param int                 $ttl    TTL in **seconds**; must be > 0
 *
 * @return int|false how many fields got the TTL; false if `$ttl <= 0` / out of
 *                   range, or on failure
 */
function ephpm_kv_hexpire(string $key, string|array $fields, int $ttl): int|false
{
}

/**
 * Remaining TTL of a hash field in **seconds** (rounded up).
 *
 * @return int seconds remaining, -1 if the field has no TTL, -2 if there is no
 *             such field
 */
function ephpm_kv_httl(string $key, string $field): int
{
}

/**
 * Remaining TTL of a hash field in **milliseconds**.
 *
 * @return int milliseconds remaining, -1 if the field has no TTL, -2 if there
 *             is no such field
 */
function ephpm_kv_hpttl(string $key, string $field): int
{
}

/**
 * Remove a hash field's TTL.
 *
 * @return bool true if a field TTL was removed
 */
function ephpm_kv_hpersist(string $key, string $field): bool
{
}

// ── Strings and keys ────────────────────────────────────────────────────────

/**
 * Get several string keys in one call.
 *
 * @param list<string> $keys
 *
 * @return list<string|null>|false a LIST aligned with `$keys` (same order, same
 *                                 count), null for each missing key; false only
 *                                 on failure (e.g. a non-UTF-8 key -> E_WARNING)
 */
function ephpm_kv_mget(array $keys): array|false
{
}

/**
 * Set several string keys in one call, all with the same TTL.
 *
 * Values are converted to string; int array keys are written as their decimal
 * string. Under the memory limit a refusal stops the write and returns false
 * with the earlier keys already stored (not atomic).
 *
 * @param array<array-key, string> $values key => value
 * @param int                      $ttl    TTL in **seconds** applied to every
 *                                         key; 0 = no expiry
 *
 * @return bool true on success
 */
function ephpm_kv_mset(array $values, int $ttl = 0): bool
{
}

/**
 * Get a string value and delete the key.
 *
 * @return string|null the value, or null if the key was missing
 */
function ephpm_kv_getdel(string $key): ?string
{
}

/**
 * Get a string value and set its TTL in one call.
 *
 * @param int $ttl > 0 sets the TTL in **seconds**; 0 removes it (persist);
 *                 negative raises E_WARNING and returns null
 *
 * @return string|null the value, or null if the key was missing (nothing is
 *                     changed then)
 */
function ephpm_kv_getex(string $key, int $ttl): ?string
{
}

/**
 * Remove a key's TTL (strings and hashes).
 *
 * @return bool true if a TTL was removed
 */
function ephpm_kv_persist(string $key): bool
{
}

/**
 * Compare-and-set (Valkey `SET … IFEQ`): replace the value only while it
 * currently equals `$expected`. Never creates the key.
 *
 * In a cluster it is decided by the key's owner (like setnx/del_if_eq); an
 * unreachable owner yields false. Lock pattern: acquire
 * `ephpm_kv_setnx($k, $tok, $ttl)`; extend `ephpm_kv_set_if_eq($k, $tok, $tok,
 * $ttl)`; release `ephpm_kv_del_if_eq($k, $tok)`.
 *
 * @param int $ttl > 0 = new TTL in **seconds**; 0 = no TTL; -1 = keep the
 *                 current TTL
 *
 * @return bool true if the value was set
 */
function ephpm_kv_set_if_eq(string $key, string $expected, string $value, int $ttl = 0): bool
{
}

/**
 * Append to a string value.
 *
 * @return int|false the new length in bytes; false on failure
 */
function ephpm_kv_append(string $key, string $value): int|false
{
}

/**
 * Length of a string value in bytes.
 *
 * @return int 0 if the key is missing
 */
function ephpm_kv_strlen(string $key): int
{
}

/**
 * The type of the value stored at a key.
 *
 * @return string "string", "hash" or "none"
 */
function ephpm_kv_type(string $key): string
{
}

/**
 * One step of an incremental keyspace scan.
 *
 * Start with `$cursor = 0`; the scan is finished when the returned next cursor
 * is 0. Every key present for the whole scan is returned exactly once (a name
 * that is both a string and a hash is returned once per type). A page may hold
 * more than `$count` keys.
 *
 * In a cluster it lists **this node's** keyspace: every hash and every string
 * value <= `[cluster.kv] small_key_threshold` is on every node, but larger
 * string values only on their replica nodes — so a scan is not a complete
 * cluster-wide listing.
 *
 * @param int         $cursor 0 to start, then the cursor returned by the previous call
 * @param string|null $match  a Redis glob (`*`, `?`, `[abc]`), or null for all keys
 * @param int         $count  page-size hint (>= 1)
 *
 * @return array{0: int, 1: list<string>}|false [next cursor, keys]; false on
 *                                              failure
 */
function ephpm_kv_scan(int $cursor, ?string $match = null, int $count = 10): array|false
{
}
