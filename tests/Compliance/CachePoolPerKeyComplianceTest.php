<?php

declare(strict_types=1);

namespace Ephpm\Cache\Tests\Compliance;

use Ephpm\Cache\KvBackend;
use Ephpm\Cache\Psr6\CachePool;
use Psr\Cache\CacheItemPoolInterface;

/**
 * The PSR-6 compliance suite again, with the batched ePHPm v0.13.0+
 * functions (ephpm_kv_mget / ephpm_kv_mset) switched off — i.e. the per-key
 * path every older ePHPm takes.
 */
final class CachePoolPerKeyComplianceTest extends CachePoolComplianceTest
{
    public function createCachePool(): CacheItemPoolInterface
    {
        return new CachePool(new KvBackend('psr6:', false));
    }
}
