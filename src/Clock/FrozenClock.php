<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Clock;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * A clock that only moves when told to; for tests of token lifetimes.
 */
final class FrozenClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(DateTimeImmutable|int|null $now = null)
    {
        $this->now = match (true) {
            $now instanceof DateTimeImmutable => $now,
            is_int($now) => (new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('UTC')),
            default => new DateTimeImmutable('now', new DateTimeZone('UTC')),
        };
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function set(DateTimeImmutable|int $now): void
    {
        $this->now = is_int($now) ? (new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('UTC')) : $now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('%+d seconds', $seconds));
    }
}
