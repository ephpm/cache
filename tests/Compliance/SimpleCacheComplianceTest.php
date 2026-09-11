<?php

declare(strict_types=1);

namespace Ephpm\Cache\Tests\Compliance;

use Cache\IntegrationTests\SimpleCacheTest;
use Ephpm\Cache\Psr16\Cache;
use PHPUnit\Framework\Attributes\After;
use Psr\SimpleCache\CacheInterface;

/**
 * Runs the official `cache/integration-tests` PSR-16 (SimpleCache) compliance
 * suite against {@see Cache}, backed by the in-memory `ephpm_kv_*` fakes from
 * the test bootstrap.
 *
 * @see \Cache\IntegrationTests\SimpleCacheTest
 */
final class SimpleCacheComplianceTest extends SimpleCacheTest
{
    protected function setUp(): void
    {
        parent::setUp();

        // Genuinely-unsupported features are skipped here (never silently), each
        // with a reason:
        $this->skippedTests = [
            // clear() cannot scope to one namespace: the SAPI exposes only
            // ephpm_kv_flush_all(), which would wipe sessions and every other
            // tenant of the shared store. Our clear() is therefore a no-op that
            // returns false + E_USER_WARNING, so the suite's expectation that
            // clear() empties the cache and returns true does not hold.
            'testClear' => 'clear() is an intentional no-op (returns false + '
                . 'E_USER_WARNING); the ePHPm SAPI has no per-namespace flush.',
        ];
    }

    public function createSimpleCache(): CacheInterface
    {
        return new Cache();
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
