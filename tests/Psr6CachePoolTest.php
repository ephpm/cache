<?php

declare(strict_types=1);

namespace Ephpm\Cache\Tests;

use DateInterval;
use DateTimeImmutable;
use Ephpm\Cache\Exception\InvalidArgumentException;
use Ephpm\Cache\Exception\UnsupportedOperationException;
use Ephpm\Cache\Psr6\CacheItem;
use Ephpm\Cache\Psr6\CachePool;
use PHPUnit\Framework\TestCase;

final class Psr6CachePoolTest extends TestCase
{
    private CachePool $pool;

    protected function setUp(): void
    {
        \EphpmKvFake::reset();
        $this->pool = new CachePool();
    }

    public function testMissItemIsNotAHit(): void
    {
        $item = $this->pool->getItem('absent');

        self::assertInstanceOf(CacheItem::class, $item);
        self::assertFalse($item->isHit());
        self::assertNull($item->get());
        self::assertSame('absent', $item->getKey());
    }

    public function testSaveThenGetIsAHit(): void
    {
        $item = $this->pool->getItem('greeting');
        $item->set('hello');
        self::assertTrue($this->pool->save($item));

        $fetched = $this->pool->getItem('greeting');
        self::assertTrue($fetched->isHit());
        self::assertSame('hello', $fetched->get());
    }

    public function testObjectRoundTrip(): void
    {
        $obj = new \stdClass();
        $obj->x = [1, 2, 3];

        $item = $this->pool->getItem('obj')->set($obj);
        $this->pool->save($item);

        self::assertEquals($obj, $this->pool->getItem('obj')->get());
    }

    public function testDeferredSaveNotVisibleInStoreUntilCommit(): void
    {
        $item = $this->pool->getItem('deferred')->set('pending');
        self::assertTrue($this->pool->saveDeferred($item));

        // Not yet written through to the backing store...
        self::assertArrayNotHasKey('psr6:deferred', \EphpmKvFake::$store);
        // ...but hasItem/getItem see it via the deferred queue.
        self::assertTrue($this->pool->hasItem('deferred'));
        self::assertTrue($this->pool->getItem('deferred')->isHit());

        self::assertTrue($this->pool->commit());

        // Now it is persisted.
        self::assertArrayHasKey('psr6:deferred', \EphpmKvFake::$store);
    }

    public function testCommitWithNoDeferredIsNoop(): void
    {
        self::assertTrue($this->pool->commit());
    }

    public function testDeleteItem(): void
    {
        $this->pool->save($this->pool->getItem('k')->set('v'));
        self::assertTrue($this->pool->hasItem('k'));

        self::assertTrue($this->pool->deleteItem('k'));
        self::assertFalse($this->pool->hasItem('k'));
    }

    public function testDeleteItemsAndGetItems(): void
    {
        $this->pool->save($this->pool->getItem('a')->set(1));
        $this->pool->save($this->pool->getItem('b')->set(2));

        $items = $this->pool->getItems(['a', 'b', 'c']);
        self::assertTrue($items['a']->isHit());
        self::assertTrue($items['b']->isHit());
        self::assertFalse($items['c']->isHit());

        self::assertTrue($this->pool->deleteItems(['a', 'b']));
        self::assertFalse($this->pool->hasItem('a'));
        self::assertFalse($this->pool->hasItem('b'));
    }

    public function testExpiresAfterIntTtl(): void
    {
        $item = $this->pool->getItem('exp')->set('v')->expiresAfter(60);
        $this->pool->save($item);

        self::assertTrue($this->pool->hasItem('exp'));
        self::assertSame('v', $this->pool->getItem('exp')->get());
    }

    public function testExpiresAfterDateInterval(): void
    {
        $item = $this->pool->getItem('expd')->set('v')->expiresAfter(new DateInterval('PT90S'));
        $this->pool->save($item);

        self::assertTrue($this->pool->hasItem('expd'));
    }

    public function testExpiresAtInThePastDeletesOnSave(): void
    {
        $past = (new DateTimeImmutable())->modify('-10 seconds');
        $item = $this->pool->getItem('gone')->set('v')->expiresAt($past);

        self::assertTrue($this->pool->save($item));
        self::assertFalse($this->pool->hasItem('gone'));
    }

    public function testExpiresAfterNullMeansNoExpiry(): void
    {
        $item = $this->pool->getItem('forever')->set('v')->expiresAfter(null);
        $this->pool->save($item);

        // ttl() of -1 (no expiry) must still read as present.
        self::assertTrue($this->pool->hasItem('forever'));
    }

    public function testInvalidKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->pool->getItem('bad@key');
    }

    public function testClearThrowsUnsupported(): void
    {
        $this->expectException(UnsupportedOperationException::class);
        $this->pool->clear();
    }

    public function testNamespaceIsolationFromPsr16(): void
    {
        $this->pool->save($this->pool->getItem('shared')->set('six'));

        self::assertArrayHasKey('psr6:shared', \EphpmKvFake::$store);
        self::assertArrayNotHasKey('psr16:shared', \EphpmKvFake::$store);
    }
}
