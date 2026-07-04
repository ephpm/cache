<?php

declare(strict_types=1);

namespace Ephpm\Cache\Tests;

use DateInterval;
use Ephpm\Cache\Exception\InvalidArgumentException;
use Ephpm\Cache\KvBackend;
use PHPUnit\Framework\TestCase;

final class KvBackendTest extends TestCase
{
    private KvBackend $backend;

    protected function setUp(): void
    {
        \EphpmKvFake::reset();
        $this->backend = new KvBackend('test:');
    }

    public function testNormalizeTtlNull(): void
    {
        self::assertNull($this->backend->normalizeTtl(null));
    }

    public function testNormalizeTtlInt(): void
    {
        self::assertSame(300, $this->backend->normalizeTtl(300));
    }

    public function testNormalizeTtlDateInterval(): void
    {
        // 2 minutes = 120 seconds.
        self::assertSame(120, $this->backend->normalizeTtl(new DateInterval('PT2M')));
    }

    public function testSerializeRoundTrip(): void
    {
        $value = ['nested' => ['deep' => new \stdClass()]];
        $raw = $this->backend->serialize($value);

        self::assertIsString($raw);
        self::assertEquals($value, $this->backend->unserialize($raw));
    }

    public function testStorageKeyPrefixing(): void
    {
        self::assertSame('test:foo', $this->backend->storageKey('foo'));
    }

    public function testReservedCharactersRejected(): void
    {
        foreach (['a{b', 'a}b', 'a(b', 'a)b', 'a/b', 'a\\b', 'a@b', 'a:b'] as $bad) {
            try {
                $this->backend->validateKey($bad);
                self::fail("Expected rejection of key: {$bad}");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testValidKeyAccepted(): void
    {
        self::assertSame('good.key_123-x', $this->backend->validateKey('good.key_123-x'));
    }

    public function testTtlSecondsReturnsNullForNoExpiry(): void
    {
        $this->backend->set('perm', 'v', null);
        // -1 from the SAPI (no expiry) surfaces as null.
        self::assertNull($this->backend->ttlSeconds('perm'));
    }

    public function testTtlSecondsReturnsNullForMissing(): void
    {
        // -2 from the SAPI (missing) surfaces as null.
        self::assertNull($this->backend->ttlSeconds('nope'));
    }

    public function testTtlSecondsReturnsRemaining(): void
    {
        $this->backend->set('temp', 'v', 100);
        $remaining = $this->backend->ttlSeconds('temp');

        self::assertIsInt($remaining);
        self::assertGreaterThan(0, $remaining);
        self::assertLessThanOrEqual(100, $remaining);
    }

    public function testSetWithNonPositiveTtlDeletes(): void
    {
        $this->backend->set('a', 'v', null);
        // A DateInterval resolving to <= 0 (or explicit 0) should not persist.
        self::assertTrue($this->backend->set('a', 'v', 0));
        self::assertFalse($this->backend->has('a'));
    }
}
