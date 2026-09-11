<?php

declare(strict_types=1);

namespace Ephpm\Cache\Exception;

/**
 * Reserved for cache operations that cannot be honoured against ePHPm's shared
 * KV store.
 *
 * Historically {@see \Ephpm\Cache\Psr16\Cache::clear()} /
 * {@see \Ephpm\Cache\Psr6\CachePool::clear()} threw this, because the SAPI only
 * exposes `ephpm_kv_flush_all()` (which wipes the *entire* shared store, not
 * just one namespace). To stay compatible with PSR consumers and the PSR
 * compliance suite -- both of which require `clear()` to return a `bool` --
 * those methods now degrade gracefully (no-op, return `false`, and emit an
 * `E_USER_WARNING`) instead of throwing. This type is retained as public API
 * for any genuinely unsupported operation a future version may need to signal.
 */
final class UnsupportedOperationException extends \RuntimeException
{
}
