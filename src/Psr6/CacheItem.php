<?php

declare(strict_types=1);

namespace Ephpm\Cache\Psr6;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Psr\Cache\CacheItemInterface;

/**
 * A single PSR-6 cache item.
 *
 * Instances are always vended by {@see CachePool}; they are never constructed
 * directly by application code. The pool populates {@see $value} and
 * {@see $isHit} at fetch time, and reads {@see $expiry} back out at save time.
 */
final class CacheItem implements CacheItemInterface
{
    /**
     * Absolute expiry moment, or null for "no expiry".
     */
    private ?DateTimeInterface $expiry = null;

    /**
     * @internal Use {@see CachePool::getItem()}.
     *
     * @param string $key   Already-validated caller key (un-prefixed).
     * @param mixed  $value Stored value, or null on a miss.
     * @param bool   $isHit Whether the key was present in the store.
     */
    public function __construct(
        private readonly string $key,
        private mixed $value,
        private bool $isHit,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->isHit ? $this->value : null;
    }

    public function isHit(): bool
    {
        return $this->isHit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;
        // Setting a value marks the item as present for subsequent reads on
        // this in-memory instance, per common PSR-6 implementation behaviour.
        $this->isHit = true;

        return $this;
    }

    public function expiresAt(?DateTimeInterface $expiration): static
    {
        $this->expiry = $expiration;

        return $this;
    }

    public function expiresAfter(int|DateInterval|null $time): static
    {
        if ($time === null) {
            $this->expiry = null;

            return $this;
        }

        $now = new DateTimeImmutable();

        $this->expiry = \is_int($time)
            ? $now->add(new DateInterval('PT' . max(0, $time) . 'S'))
            : $now->add($time);

        return $this;
    }

    /**
     * @internal Consumed by {@see CachePool} at save time.
     *
     * @return int|null Remaining TTL in seconds, or null for no expiry.
     *                  A non-positive result signals an already-expired item.
     */
    public function ttlSeconds(): ?int
    {
        if ($this->expiry === null) {
            return null;
        }

        return $this->expiry->getTimestamp() - (new DateTimeImmutable())->getTimestamp();
    }

    /**
     * @internal Consumed by {@see CachePool} at save time.
     */
    public function rawValue(): mixed
    {
        return $this->value;
    }
}
