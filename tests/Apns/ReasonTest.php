<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Tests\Apns;

use Aporat\PushNotifications\Apns\Reason;
use Aporat\PushNotifications\Outcome;
use PHPUnit\Framework\TestCase;

final class ReasonTest extends TestCase
{
    public function test_every_reason_has_an_outcome(): void
    {
        foreach (Reason::cases() as $reason) {
            self::assertNotSame(Outcome::Delivered, $reason->outcome(), $reason->value);
        }
    }

    public function test_apples_do_not_retry_list_is_not_retryable(): void
    {
        foreach ([Reason::BadDeviceToken, Reason::DeviceTokenNotForTopic, Reason::Forbidden, Reason::ExpiredToken, Reason::Unregistered, Reason::PayloadTooLarge] as $reason) {
            self::assertFalse($reason->outcome()->isRetryable(), $reason->value);
        }
    }

    public function test_retryable_reasons(): void
    {
        foreach ([Reason::TooManyRequests, Reason::InternalServerError, Reason::ServiceUnavailable, Reason::Shutdown, Reason::IdleTimeout] as $reason) {
            self::assertTrue($reason->outcome()->isRetryable(), $reason->value);
        }
    }

    public function test_dead_tokens(): void
    {
        self::assertTrue(Reason::Unregistered->outcome()->tokenIsUnregistered());
        self::assertTrue(Reason::ExpiredToken->outcome()->tokenIsUnregistered());
        self::assertFalse(Reason::BadDeviceToken->outcome()->tokenIsUnregistered());
    }

    public function test_status_fallback(): void
    {
        self::assertSame(Outcome::Unregistered, Reason::outcomeForStatus(410));
        self::assertSame(Outcome::RateLimited, Reason::outcomeForStatus(429));
        self::assertSame(Outcome::Unauthorized, Reason::outcomeForStatus(403));
        self::assertSame(Outcome::Unavailable, Reason::outcomeForStatus(502));
        self::assertSame(Outcome::Failed, Reason::outcomeForStatus(418));
    }
}
