<?php

declare(strict_types=1);

namespace Ephpm\Cache\Tests\Compliance;

use Ephpm\Cache\KvBackend;
use Ephpm\Cache\Psr16\Cache;
use Psr\SimpleCache\CacheInterface;

/**
 * The PSR-16 compliance suite again, with the batched ePHPm v0.13.0+
 * functions (ephpm_kv_mget / ephpm_kv_mset) switched off — i.e. the per-key
 * path every older ePHPm takes.
 */
final class SimpleCachePerKeyComplianceTest extends SimpleCacheComplianceTest
{
    public function createSimpleCache(): CacheInterface
    {
        return new Cache(null, new KvBackend('psr16:', false));
    }
}
