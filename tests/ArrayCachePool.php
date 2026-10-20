<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Tests;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * The smallest PSR-6 pool that behaves: in memory, honouring expiry.
 */
final class ArrayCachePool implements CacheItemPoolInterface
{
    /** @var array<string, array{value: mixed, expires: int|null}> */
    private array $items = [];

    public int $saves = 0;

    public function getItem(string $key): CacheItemInterface
    {
        $stored = $this->items[$key] ?? null;

        if ($stored !== null && $stored['expires'] !== null && $stored['expires'] <= time()) {
            unset($this->items[$key]);
            $stored = null;
        }

        return new ArrayCacheItem($key, $stored['value'] ?? null, $stored !== null);
    }

    /**
     * @param  array<string>  $keys
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->getItem($key);
        }
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->deleteItem($key);
        }

        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (! $item instanceof ArrayCacheItem) {
            return false;
        }

        $this->saves++;
        $this->items[$item->getKey()] = ['value' => $item->get(), 'expires' => $item->expiresAtTimestamp()];

        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    public function commit(): bool
    {
        return true;
    }
}

final class ArrayCacheItem implements CacheItemInterface
{
    private ?int $expires = null;

    public function __construct(
        private readonly string $key,
        private mixed $value,
        private bool $hit,
    ) {}

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->hit ? $this->value : null;
    }

    public function isHit(): bool
    {
        return $this->hit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;
        $this->hit = true;

        return $this;
    }

    public function expiresAt(?DateTimeInterface $expiration): static
    {
        $this->expires = $expiration?->getTimestamp();

        return $this;
    }

    public function expiresAfter(DateInterval|int|null $time): static
    {
        $this->expires = match (true) {
            $time === null => null,
            is_int($time) => time() + $time,
            default => (new DateTimeImmutable)->add($time)->getTimestamp(),
        };

        return $this;
    }

    public function expiresAtTimestamp(): ?int
    {
        return $this->expires;
    }
}
