<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Tests;

use Aporat\PushNotifications\Outcome;
use Aporat\PushNotifications\Platform;
use Aporat\PushNotifications\Response;
use PHPUnit\Framework\TestCase;

final class OutcomeTest extends TestCase
{
    public function test_only_rate_limited_and_unavailable_retry(): void
    {
        foreach (Outcome::cases() as $outcome) {
            self::assertSame(in_array($outcome, [Outcome::RateLimited, Outcome::Unavailable], true), $outcome->isRetryable(), $outcome->value);
        }
    }

    public function test_only_unregistered_drops_the_token(): void
    {
        foreach (Outcome::cases() as $outcome) {
            self::assertSame($outcome === Outcome::Unregistered, $outcome->tokenIsUnregistered(), $outcome->value);
        }
    }

    public function test_transport_failure(): void
    {
        $response = Response::transportFailure(Platform::Apns, 'tok', 'boom');

        self::assertSame(Outcome::Unavailable, $response->outcome);
        self::assertSame(0, $response->statusCode);
        self::assertSame('TransportFailure', $response->reason);
        self::assertSame('boom', $response->message);
        self::assertTrue($response->isRetryable());
        self::assertFalse($response->succeeded());
    }
}
