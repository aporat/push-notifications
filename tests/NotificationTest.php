<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Tests;

use Aporat\PushNotifications\Apns\InterruptionLevel;
use Aporat\PushNotifications\Apns\Message as ApnsMessage;
use Aporat\PushNotifications\Fcm\Message as FcmMessage;
use Aporat\PushNotifications\Fcm\Target;
use Aporat\PushNotifications\Notification;
use Aporat\PushNotifications\Priority;
use PHPUnit\Framework\TestCase;

final class NotificationTest extends TestCase
{
    private function notification(): Notification
    {
        return Notification::create('New collab', 'Someone wants to work with you')
            ->subtitle('GrowUp')
            ->badge(3)
            ->sound('default')
            ->data(['type' => 'collab', 'id' => 42])
            ->collapseId('collab-42')
            ->ttl(3600);
    }

    public function test_renders_an_apns_alert(): void
    {
        $message = $this->notification()->contentAvailable()->toApns('ab12', 'com.example.app');
        $message->validate();

        $headers = $message->headers();
        self::assertSame('com.example.app', $headers['apns-topic']);
        self::assertSame('alert', $headers['apns-push-type']);
        self::assertSame('10', $headers['apns-priority']);
        self::assertSame('collab-42', $headers['apns-collapse-id']);
        self::assertGreaterThanOrEqual(time() + 3599, (int) $headers['apns-expiration']);

        self::assertSame([
            'aps' => [
                'alert' => ['title' => 'New collab', 'subtitle' => 'GrowUp', 'body' => 'Someone wants to work with you'],
                'badge' => 3,
                'sound' => 'default',
                'content-available' => 1,
            ],
            'type' => 'collab',
            'id' => 42,
        ], json_decode($message->body(), true));
    }

    public function test_normal_priority_maps_to_conserve(): void
    {
        $message = Notification::create('x')->priority(Priority::Normal)->toApns('ab', 'com.example.app');

        self::assertSame('5', $message->headers()['apns-priority']);
        self::assertSame('normal', Notification::create('x')->priority(Priority::Normal)->toFcm('t')->toArray()['android']['priority']);
    }

    public function test_apns_customizer_sees_the_rendered_message(): void
    {
        $message = Notification::create('x')
            ->apns(function (ApnsMessage $m): void {
                $m->payload()->interruptionLevel(InterruptionLevel::TimeSensitive)->threadId('t1');
                $m->neverStore();
            })
            ->toApns('ab', 'com.example.app');

        self::assertSame('0', $message->headers()['apns-expiration']);
        self::assertStringContainsString('"interruption-level":"time-sensitive"', $message->body());
        self::assertStringContainsString('"thread-id":"t1"', $message->body());
    }

    public function test_renders_an_fcm_notification_message(): void
    {
        $message = $this->notification()->image('https://img')->toFcm('tok');
        $message->validate();

        self::assertSame([
            'token' => 'tok',
            'notification' => ['title' => 'New collab', 'body' => 'Someone wants to work with you', 'image' => 'https://img'],
            'data' => ['type' => 'collab', 'id' => '42'],
            'android' => [
                'priority' => 'high',
                'notification' => ['notification_count' => 3, 'sound' => 'default'],
                'collapse_key' => 'collab-42',
                'ttl' => '3600s',
            ],
            'apns' => ['payload' => ['aps' => ['badge' => 3, 'sound' => 'default']]],
        ], $message->toArray());
    }

    public function test_renders_an_fcm_data_only_message(): void
    {
        $message = $this->notification()->dataOnly()->toFcm(Target::fid('fid-1'));
        $message->validate();

        self::assertSame([
            'token' => 'fid-1',
            'data' => ['type' => 'collab', 'id' => '42', 'title' => 'New collab', 'body' => 'Someone wants to work with you', 'badge' => '3'],
            'android' => ['priority' => 'high', 'collapse_key' => 'collab-42', 'ttl' => '3600s'],
        ], $message->toArray());
        self::assertFalse($message->hasNotification());
    }

    public function test_fcm_customizer_sees_the_rendered_message(): void
    {
        $message = Notification::create('x')
            ->fcm(fn (FcmMessage $m) => $m->channelId('alerts')->analyticsLabel('a'))
            ->toFcm('tok');

        $array = $message->toArray();
        self::assertSame('alerts', $array['android']['notification']['channel_id']);
        self::assertSame('a', $array['fcm_options']['analytics_label']);
    }

    public function test_data_merges_with_later_keys_winning(): void
    {
        $data = Notification::create('x')->data(['a' => 1, 'b' => 1])->data(['b' => 2])->toFcm('t')->getData();

        self::assertSame(['b' => '2', 'a' => '1'], $data);
    }
}
