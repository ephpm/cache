<?php

declare(strict_types=1);

namespace Ephpm\Cache\Tests\Compliance;

use Cache\IntegrationTests\CachePoolTest;
use Ephpm\Cache\Psr6\CachePool;
use PHPUnit\Framework\Attributes\After;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Runs the official `cache/integration-tests` PSR-6 compliance suite against
 * {@see CachePool}, backed by the in-memory `ephpm_kv_*` fakes from the test
 * bootstrap.
 *
 * @see \Cache\IntegrationTests\CachePoolTest
 */
final class CachePoolComplianceTest extends CachePoolTest
{
    protected function setUp(): void
    {
        parent::setUp();

        // Genuinely-unsupported features are skipped here (never silently), each
        // with a reason. All three skips share one root cause: the ePHPm SAPI
        // has no per-namespace flush -- only ephpm_kv_flush_all(), which would
        // wipe sessions and every other tenant of the shared store -- so
        // clear() is an intentional no-op (returns false + E_USER_WARNING).
        $reason = 'clear() is an intentional no-op (returns false + '
            . 'E_USER_WARNING); the ePHPm SAPI has no per-namespace flush.';

        $this->skippedTests = [
            'testClear' => $reason,
            'testClearWithDeferredItems' => $reason,
            // testBasicUsage clears mid-test and asserts the items are evicted;
            // core get/set/delete behaviour is covered by Psr6CachePoolTest.
            'testBasicUsage' => $reason,
        ];
    }

    public function createCachePool(): CacheItemPoolInterface
    {
        return new CachePool();
    }

    /**
     * The parent's #[After] hook calls $cache->clear() to reset state between
     * tests. Our clear() is a no-op, so instead we wipe the shared in-memory KV
     * fake directly -- this both isolates tests and avoids the (expected)
     * E_USER_WARNING that clear() would otherwise raise into the teardown.
     */
    #[After]
    public function tearDownService(): void
    {
        \EphpmKvFake::reset();
    }
}
