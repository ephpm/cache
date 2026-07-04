<?php

declare(strict_types=1);

namespace Ephpm\Cache\Tests;

use DateInterval;
use Ephpm\Cache\Exception\InvalidArgumentException;
use Ephpm\Cache\Exception\UnsupportedOperationException;
use Ephpm\Cache\Psr16\Cache;
use PHPUnit\Framework\TestCase;

final class Psr16CacheTest extends TestCase
{
    private Cache $cache;

    protected function setUp(): void
    {
        \EphpmKvFake::reset();
        $this->cache = new Cache();
    }

    public function testMissReturnsDefault(): void
    {
        self::assertNull($this->cache->get('absent'));
        self::assertSame('fallback', $this->cache->get('absent', 'fallback'));
    }

    public function testStoredNullIsAHitNotAMiss(): void
    {
        $this->cache->set('nullable', null);

        self::assertTrue($this->cache->has('nullable'));
        // Even though the stored value is null, the default must NOT be used.
        self::assertNull($this->cache->get('nullable', 'default-should-not-win'));
    }

    public function testObjectRoundTripThroughSerialize(): void
    {
        $obj = new \stdClass();
        $obj->name = 'ada';
        $obj->nested = new \stdClass();
        $obj->nested->n = 42;

        $this->cache->set('obj', $obj);
        $out = $this->cache->get('obj');

        self::assertEquals($obj, $out);
        self::assertSame('ada', $out->name);
        self::assertSame(42, $out->nested->n);
    }

    public function testArrayRoundTrip(): void
    {
        $data = ['a' => 1, 'b' => [2, 3], 'c' => true, 'd' => null];
        $this->cache->set('arr', $data);

        self::assertSame($data, $this->cache->get('arr'));
    }

    public function testHasAndDelete(): void
    {
        $this->cache->set('k', 'v');
        self::assertTrue($this->cache->has('k'));

        self::assertTrue($this->cache->delete('k'));
        self::assertFalse($this->cache->has('k'));
        // Deleting an absent key still returns true per PSR-16.
        self::assertTrue($this->cache->delete('k'));
    }

    public function testGetMultipleAndSetMultiple(): void
    {
        self::assertTrue($this->cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]));

        $out = $this->cache->getMultiple(['a', 'b', 'missing'], 'D');

        self::assertSame(['a' => 1, 'b' => 2, 'missing' => 'D'], $out);
    }

    public function testDeleteMultiple(): void
    {
        $this->cache->setMultiple(['a' => 1, 'b' => 2]);
        self::assertTrue($this->cache->deleteMultiple(['a', 'b']));

        self::assertFalse($this->cache->has('a'));
        self::assertFalse($this->cache->has('b'));
    }

    public function testIntTtlExpires(): void
    {
        $this->cache->set('ttl', 'x', 1);
        self::assertTrue($this->cache->has('ttl'));

        // Advance the fake clock by mutating the stored expiry into the past.
        foreach (\EphpmKvFake::$store as $key => &$entry) {
            if (str_ends_with($key, 'ttl')) {
                $entry['expires'] = time() - 1;
            }
        }
        unset($entry);

        self::assertFalse($this->cache->has('ttl'));
        self::assertNull($this->cache->get('ttl'));
    }

    public function testDateIntervalTtlIsAccepted(): void
    {
        $this->cache->set('di', 'y', new DateInterval('PT60S'));

        self::assertTrue($this->cache->has('di'));
        self::assertSame('y', $this->cache->get('di'));
    }

    public function testDefaultTtlApplied(): void
    {
        $cache = new Cache(defaultTtl: 120);
        $cache->set('withdefault', 'z');

        self::assertTrue($cache->has('withdefault'));
    }

    public function testInvalidKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->get('bad{key}');
    }

    public function testEmptyKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->set('', 'v');
    }

    public function testInvalidArgumentImplementsPsr16Marker(): void
    {
        $e = new InvalidArgumentException('x');
        self::assertInstanceOf(\Psr\SimpleCache\InvalidArgumentException::class, $e);
        self::assertInstanceOf(\Psr\Cache\InvalidArgumentException::class, $e);
    }

    public function testClearThrowsUnsupported(): void
    {
        $this->expectException(UnsupportedOperationException::class);
        $this->cache->clear();
    }

    public function testNamespaceIsolationFromPsr6(): void
    {
        // Same logical key under psr16 must not be visible with a psr6 prefix.
        $this->cache->set('shared', 'sixteen');

        self::assertArrayHasKey('psr16:shared', \EphpmKvFake::$store);
        self::assertArrayNotHasKey('psr6:shared', \EphpmKvFake::$store);
    }
}
