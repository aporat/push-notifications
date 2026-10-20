<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Tests\Testing;

use Aporat\PushNotifications\Apns\Message as ApnsMessage;
use Aporat\PushNotifications\Contracts\Message;
use Aporat\PushNotifications\Exceptions\InvalidMessageException;
use Aporat\PushNotifications\Fcm\Message as FcmMessage;
use Aporat\PushNotifications\Outcome;
use Aporat\PushNotifications\Platform;
use Aporat\PushNotifications\Response;
use Aporat\PushNotifications\Testing\FakeSender;
use PHPUnit\Framework\TestCase;

final class FakeSenderTest extends TestCase
{
    public function test_records_and_delivers_by_default(): void
    {
        $fake = new FakeSender;

        $response = $fake->send(ApnsMessage::alert('ab')->bundleId('com.example.app'));

        self::assertTrue($response->succeeded());
        self::assertSame(200, $response->statusCode);
        self::assertSame(Platform::Apns, $response->platform);
        self::assertNotNull($response->id);
        self::assertCount(1, $fake->sent());
        self::assertSame(1, $fake->count());
        self::assertCount(1, $fake->sentTo('ab'));
        self::assertCount(0, $fake->sentTo('cd'));
    }

    public function test_fixed_outcome(): void
    {
        $fake = new FakeSender(Outcome::Unregistered);

        $response = $fake->send(FcmMessage::to('t')->notification('x'));

        self::assertSame(Outcome::Unregistered, $response->outcome);
        self::assertSame(410, $response->statusCode);
        self::assertSame('FakeUnregistered', $response->reason);
    }

    public function test_callback_per_message(): void
    {
        $fake = new FakeSender(fn (Message $m): Outcome|Response => $m->target() === 'dead'
            ? Outcome::Unregistered
            : new Response(Platform::Fcm, $m->target(), Outcome::Delivered, id: 'custom'));

        $responses = $fake->sendMany(['a' => FcmMessage::to('live')->notification('x'), 'b' => FcmMessage::to('dead')->notification('x')]);

        self::assertSame('custom', $responses['a']->id);
        self::assertTrue($responses['b']->tokenIsUnregistered());
        self::assertSame(2, $fake->count());

        $fake->reset();
        self::assertSame(0, $fake->count());
    }

    public function test_still_validates(): void
    {
        $this->expectException(InvalidMessageException::class);

        (new FakeSender)->send(ApnsMessage::alert('ab'));
    }
}
