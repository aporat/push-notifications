<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Tests\Fcm;

use Aporat\PushNotifications\Exceptions\InvalidMessageException;
use Aporat\PushNotifications\Fcm\AndroidPriority;
use Aporat\PushNotifications\Fcm\Message;
use Aporat\PushNotifications\Fcm\Target;
use Aporat\PushNotifications\Platform;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    public function test_notification_message(): void
    {
        $message = Message::to('tok')
            ->notification('Title', 'Body', 'https://img')
            ->data(['k' => 'v'])
            ->priority(AndroidPriority::High)
            ->ttl(60)
            ->collapseKey('ck')
            ->channelId('chat')
            ->badge(4)
            ->sound('ping')
            ->analyticsLabel('promo');

        $message->validate();

        self::assertSame([
            'token' => 'tok',
            'notification' => ['title' => 'Title', 'body' => 'Body', 'image' => 'https://img'],
            'data' => ['k' => 'v'],
            'android' => [
                'priority' => 'high',
                'ttl' => '60s',
                'collapse_key' => 'ck',
                'notification' => ['channel_id' => 'chat', 'notification_count' => 4, 'sound' => 'ping'],
            ],
            'apns' => ['payload' => ['aps' => ['badge' => 4, 'sound' => 'ping']]],
            'fcm_options' => ['analytics_label' => 'promo'],
        ], $message->toArray());
        self::assertSame(['message' => $message->toArray()], $message->toRequest());
        self::assertSame(Platform::Fcm, $message->platform());
        self::assertSame('tok', $message->target());
    }

    public function test_data_values_are_cast_to_strings(): void
    {
        $data = Message::to('t')->data([
            'int' => 1,
            'float' => 1.5,
            'yes' => true,
            'no' => false,
            'null' => null,
            'list' => ['a', 'b'],
            'map' => ['x' => 1],
            '' => 'dropped',
        ])->getData();

        self::assertSame([
            'int' => '1',
            'float' => '1.5',
            'yes' => 'true',
            'no' => 'false',
            'list' => '["a","b"]',
            'map' => '{"x":1}',
        ], $data);
    }

    public function test_data_only_message(): void
    {
        $message = Message::to(Target::fid('fid-1'))->data(['title' => 'T'])->priority(AndroidPriority::High);
        $message->validate();

        self::assertSame(['token' => 'fid-1', 'data' => ['title' => 'T'], 'android' => ['priority' => 'high']], $message->toArray());
        self::assertFalse($message->hasNotification());
    }

    public function test_topic_and_condition(): void
    {
        $topic = Message::to(Target::topic('news'))->notification('x');
        $topic->validate();
        self::assertSame(['topic' => 'news'], array_slice($topic->toArray(), 0, 1));
        self::assertSame('topic:news', $topic->target());

        $condition = Message::to(Target::condition("'a' in topics && 'b' in topics"))->notification('x');
        $condition->validate();
        self::assertSame("condition:'a' in topics && 'b' in topics", $condition->target());
    }

    public function test_invalid_topic_name(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('not a valid FCM topic');

        Message::to(Target::topic('/topics/news'))->notification('x')->validate();
    }

    public function test_empty_target_is_refused(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('token is empty');

        Message::to('')->notification('x')->validate();
    }

    public function test_empty_message_is_refused(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('neither a notification nor data');

        Message::to('t')->validate();
    }

    public function test_reserved_data_keys_are_refused(): void
    {
        foreach (['from', 'notification', 'message_type', 'google_x', 'gcm.y'] as $key) {
            try {
                Message::to('t')->data([$key => 'v'])->validate();
                self::fail("Expected $key to be refused.");
            } catch (InvalidMessageException $e) {
                self::assertStringContainsString('reserved', $e->getMessage());
            }
        }
    }

    public function test_oversized_message_is_refused(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('FCM allows 4096');

        Message::to('t')->data(['blob' => str_repeat('a', 4100)])->validate();
    }

    public function test_raw_overrides_merge(): void
    {
        $message = Message::to('t')
            ->notification('x')
            ->android(['direct_boot_ok' => true, 'notification' => ['color' => '#ff0000']])
            ->channelId('c')
            ->apns(['headers' => ['apns-priority' => '5']])
            ->webpush(['headers' => ['Urgency' => 'high']])
            ->validateOnly();

        $array = $message->toArray();
        self::assertSame(['direct_boot_ok' => true, 'notification' => ['color' => '#ff0000', 'channel_id' => 'c']], $array['android']);
        self::assertSame(['headers' => ['apns-priority' => '5']], $array['apns']);
        self::assertSame(['headers' => ['Urgency' => 'high']], $array['webpush']);
        self::assertTrue($message->toRequest()['validate_only']);
    }
}
