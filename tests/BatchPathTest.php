<?php

declare(strict_types=1);

namespace Ephpm\Cache\Tests;

use DateInterval;
use Ephpm\Cache\Exception\InvalidArgumentException;
use Ephpm\Cache\KvBackend;
use Ephpm\Cache\Psr16\Cache;
use Ephpm\Cache\Psr6\CachePool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The multi-key operations use ephpm_kv_mget() / ephpm_kv_mset() when the
 * runtime provides them (ePHPm v0.13.0+) and fall back to per-key
 * ephpm_kv_get() / ephpm_kv_set() otherwise. Every behavioural test here runs
 * against both paths; the path-specific tests prove which one was taken.
 */
final class BatchPathTest extends TestCase
{
    protected function setUp(): void
    {
        \EphpmKvFake::reset();
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function paths(): array
    {
        return [
            'batched (v0.13.0+)' => [true],
            'per-key fallback' => [false],
        ];
    }

    private function psr16(bool $batch, null|int|DateInterval $defaultTtl = null): Cache
    {
        return new Cache($defaultTtl, new KvBackend('psr16:', $batch));
    }

    private function psr6(bool $batch): CachePool
    {
        return new CachePool(new KvBackend('psr6:', $batch));
    }

    #[DataProvider('paths')]
    public function testGetMultipleReturnsHitsMissesAndStoredNull(bool $batch): void
    {
        $cache = $this->psr16($batch);
        $cache->set('a', 1);
        $cache->set('n', null);
        $cache->set('123', 'numeric');

        $result = [];
        foreach ($cache->getMultiple(['a', 'missing', 'n', '123'], 'dflt') as $key => $value) {
            $result[] = [$key, $value];
        }

        self::assertSame(
            [['a', 1], ['missing', 'dflt'], ['n', null], ['123', 'numeric']],
            $result,
        );
        self::assertSame($batch ? ['mget' => 1] : [], \EphpmKvFake::$calls);
    }

    #[DataProvider('paths')]
    public function testSetMultipleStoresEveryValueWithTheTtl(bool $batch): void
    {
        $cache = $this->psr16($batch);

        self::assertTrue($cache->setMultiple(['a' => 1, 'b' => ['x' => 2], 7 => 'int-key'], 100));

        self::assertSame(1, $cache->get('a'));
        self::assertSame(['x' => 2], $cache->get('b'));
        self::assertSame('int-key', $cache->get('7'));

        foreach (['psr16:a', 'psr16:b', 'psr16:7'] as $storageKey) {
            $ttl = ephpm_kv_ttl($storageKey);
            self::assertGreaterThan(0, $ttl);
            self::assertLessThanOrEqual(100, $ttl);
        }

        self::assertSame($batch ? ['mset' => 1] : [], \EphpmKvFake::$calls);
    }

    #[DataProvider('paths')]
    public function testSetMultipleAcceptsDateIntervalAndDefaultTtl(bool $batch): void
    {
        $cache = $this->psr16($batch, 50);

        $cache->setMultiple(['i' => 1], new DateInterval('PT2M'));
        $cache->setMultiple(['d' => 1]);

        self::assertGreaterThan(50, ephpm_kv_ttl('psr16:i'));
        self::assertLessThanOrEqual(120, ephpm_kv_ttl('psr16:i'));
        self::assertGreaterThan(0, ephpm_kv_ttl('psr16:d'));
        self::assertLessThanOrEqual(50, ephpm_kv_ttl('psr16:d'));
    }

    #[DataProvider('paths')]
    public function testSetMultipleWithoutTtlNeverExpires(bool $batch): void
    {
        $cache = $this->psr16($batch);
        $cache->setMultiple(['a' => 1]);

        self::assertSame(-1, ephpm_kv_ttl('psr16:a'));
    }

    #[DataProvider('paths')]
    public function testSetMultipleWithExpiredTtlDeletes(bool $batch): void
    {
        $cache = $this->psr16($batch);
        $cache->setMultiple(['a' => 1, 'b' => 2]);
        \EphpmKvFake::$calls = [];

        self::assertTrue($cache->setMultiple(['a' => 9, 'b' => 9], 0));
        self::assertFalse($cache->has('a'));
        self::assertFalse($cache->has('b'));
        self::assertArrayNotHasKey('mset', \EphpmKvFake::$calls);
    }

    #[DataProvider('paths')]
    public function testSetMultipleValidatesEveryKeyBeforeWriting(bool $batch): void
    {
        $cache = $this->psr16($batch);

        try {
            $cache->setMultiple(['good' => 1, 'bad{key' => 2]);
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
        }

        self::assertFalse($cache->has('good'));
    }

    public function testGetMultipleRecoversPerKeyWhenMgetFails(): void
    {
        $cache = $this->psr16(true);
        $cache->set('a', 'v');
        \EphpmKvFake::$failMget = true;

        $result = \iterator_to_array($cache->getMultiple(['a', 'b'], 'dflt'));

        self::assertSame(['a' => 'v', 'b' => 'dflt'], $result);
        self::assertSame(['mget' => 1], \EphpmKvFake::$calls);
    }

    #[DataProvider('paths')]
    public function testGetItemsReadsTheStoreAndPrefersDeferredItems(bool $batch): void
    {
        $pool = $this->psr6($batch);
        $pool->save($pool->getItem('stored')->set('from-store'));
        $pool->save($pool->getItem('both')->set('stale'));
        $pool->saveDeferred($pool->getItem('both')->set('deferred'));

        $items = [];
        foreach ($pool->getItems(['stored', 'both', 'missing']) as $key => $item) {
            $items[$key] = [$item->isHit(), $item->get()];
        }

        self::assertSame([
            'stored' => [true, 'from-store'],
            'both' => [true, 'deferred'],
            'missing' => [false, null],
        ], $items);
        self::assertSame($batch ? ['mget' => 1] : [], \EphpmKvFake::$calls);

        $pool->commit();
    }

    #[DataProvider('paths')]
    public function testCommitWritesOneBatchPerTtlGroup(bool $batch): void
    {
        $pool = $this->psr6($batch);
        $pool->saveDeferred($pool->getItem('p1')->set(1));
        $pool->saveDeferred($pool->getItem('p2')->set(2));
        $pool->saveDeferred($pool->getItem('t1')->set(3)->expiresAfter(300));
        $pool->saveDeferred($pool->getItem('gone')->set(4)->expiresAfter(-10));

        self::assertTrue($pool->commit());

        self::assertSame(-1, ephpm_kv_ttl('psr6:p1'));
        self::assertSame(-1, ephpm_kv_ttl('psr6:p2'));
        self::assertGreaterThan(0, ephpm_kv_ttl('psr6:t1'));
        self::assertLessThanOrEqual(300, ephpm_kv_ttl('psr6:t1'));
        self::assertFalse(ephpm_kv_exists('psr6:gone'));

        self::assertSame(3, $pool->getItem('t1')->get());
        self::assertSame($batch ? ['mset' => 2] : [], \EphpmKvFake::$calls);
    }
}
