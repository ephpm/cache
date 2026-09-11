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

        // Per PSR-6, isHit() reflects whether the item was found in the pool at
        // fetch time; set()ing a value must NOT flip a previously-missed item to
        // a hit. The item only becomes a hit once it has been persisted and
        // re-fetched (or surfaced from the pool's deferred queue).

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

    /**
     * Return a copy of this item with an explicit hit state.
     *
     * The pool uses this to surface a still-deferred (uncommitted) item as a
     * hit — an item that logically lives in the pool even though it has not yet
     * been flushed to the backing store — without {@see set()} itself having to
     * lie about {@see isHit()}.
     *
     * @internal Consumed by {@see CachePool}.
     */
    public function withHitState(bool $isHit): static
    {
        $clone = clone $this;
        $clone->isHit = $isHit;

        return $clone;
    }
}
