<?php

declare(strict_types=1);

namespace Ephpm\Cache\Exception;

/**
 * Thrown when a cache operation cannot be honoured against ePHPm's shared KV
 * store.
 *
 * The canonical case is {@see \Ephpm\Cache\Psr16\Cache::clear()} /
 * {@see \Ephpm\Cache\Psr6\CachePool::clear()}: the SAPI only exposes
 * `ephpm_kv_flush_all()`, which wipes the *entire* shared store (sessions and
 * every other namespace included). Silently flushing everything to satisfy a
 * per-namespace `clear()` would be a data-loss footgun, so we surface this
 * exception instead.
 */
final class UnsupportedOperationException extends \RuntimeException
{
}
