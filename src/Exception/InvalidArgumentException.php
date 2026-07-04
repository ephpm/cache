<?php

declare(strict_types=1);

namespace Ephpm\Cache\Exception;

use Psr\Cache\InvalidArgumentException as Psr6InvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException as Psr16InvalidArgumentException;

/**
 * Thrown when a cache key (or set of keys) is malformed.
 *
 * Implements both the PSR-16 and PSR-6 marker interfaces so that a single
 * exception type satisfies the contract of either standard. This lets the
 * shared {@see \Ephpm\Cache\KvBackend} validate keys once regardless of which
 * cache facade the caller is using.
 */
final class InvalidArgumentException extends \InvalidArgumentException implements
    Psr16InvalidArgumentException,
    Psr6InvalidArgumentException
{
}
