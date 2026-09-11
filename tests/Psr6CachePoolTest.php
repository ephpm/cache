<?php

declare(strict_types=1);

namespace Ephpm\Cache\Tests;

use DateInterval;
use DateTimeImmutable;
use Ephpm\Cache\Exception\InvalidArgumentException;
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

        $items = \iterator_to_array($this->pool->getItems(['a', 'b', 'c']));
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

    public function testClearIsNoopReturningFalseAndWarns(): void
    {
        $this->pool->save($this->pool->getItem('kept')->set('v'));

        $warning = null;
        \set_error_handler(static function (int $errno, string $errstr) use (&$warning): bool {
            $warning = [$errno, $errstr];

            return true; // swallow so PHPUnit's failOnWarning does not trip
        });

        try {
            $result = $this->pool->clear();
        } finally {
            \restore_error_handler();
        }

        self::assertFalse($result, 'clear() must return false (no per-namespace flush available).');
        self::assertNotNull($warning, 'clear() must emit a warning.');
        self::assertSame(\E_USER_WARNING, $warning[0]);

        // Persisted items survive the no-op...
        self::assertTrue($this->pool->hasItem('kept'));
    }

    public function testClearDropsDeferredButNotPersistedItems(): void
    {
        $this->pool->save($this->pool->getItem('persisted')->set('p'));
        $this->pool->saveDeferred($this->pool->getItem('pending')->set('q'));

        // Swallow the expected warning.
        \set_error_handler(static fn (): bool => true);

        try {
            self::assertFalse($this->pool->clear());
        } finally {
            \restore_error_handler();
        }

        // Deferred-but-uncommitted items are private to the pool, so clear()
        // safely drops them; persisted items remain.
        self::assertFalse($this->pool->hasItem('pending'));
        self::assertTrue($this->pool->hasItem('persisted'));
    }

    public function testSetDoesNotFlipIsHitOnAMissedItem(): void
    {
        // Per PSR-6, set() must not make isHit() report true; the item is only a
        // hit once persisted and re-fetched.
        $item = $this->pool->getItem('fresh');
        self::assertFalse($item->isHit());

        $item->set('value');
        self::assertFalse($item->isHit(), 'set() must not flip isHit() on a previously-missed item.');

        $this->pool->save($item);
        self::assertTrue($this->pool->getItem('fresh')->isHit());
    }

    public function testExpiredDeferredItemIsNotAHit(): void
    {
        $past = (new DateTimeImmutable())->modify('-10 seconds');
        $item = $this->pool->getItem('stale')->set('v')->expiresAt($past);
        self::assertTrue($this->pool->saveDeferred($item));

        // An expired deferred item must read as absent, even before commit.
        self::assertFalse($this->pool->hasItem('stale'));
        self::assertFalse($this->pool->getItem('stale')->isHit());
    }

    public function testNamespaceIsolationFromPsr16(): void
    {
        $this->pool->save($this->pool->getItem('shared')->set('six'));

        self::assertArrayHasKey('psr6:shared', \EphpmKvFake::$store);
        self::assertArrayNotHasKey('psr16:shared', \EphpmKvFake::$store);
    }
}
