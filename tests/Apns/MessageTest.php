<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Tests\Apns;

use Aporat\PushNotifications\Apns\Alert;
use Aporat\PushNotifications\Apns\Message;
use Aporat\PushNotifications\Apns\Payload;
use Aporat\PushNotifications\Apns\Priority;
use Aporat\PushNotifications\Apns\PushType;
use Aporat\PushNotifications\Exceptions\InvalidMessageException;
use Aporat\PushNotifications\Platform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    private const string TOKEN = '00fc13adff785122b4ad28809a3420982341241421348097878e577c991de8f0';

    public function test_alert_headers_and_body(): void
    {
        $message = Message::alert(self::TOKEN, Payload::create()->alert(Alert::create('Hi', 'There'))->badge(3))
            ->bundleId('com.example.app')
            ->collapseId('thread-1')
            ->id('123E4567-E89B-12D3-A456-426655440000')
            ->neverStore();

        $message->validate();

        self::assertSame([
            'apns-topic' => 'com.example.app',
            'apns-push-type' => 'alert',
            'apns-priority' => '10',
            'apns-expiration' => '0',
            'apns-collapse-id' => 'thread-1',
            'apns-id' => '123e4567-e89b-12d3-a456-426655440000',
        ], $message->headers());
        self::assertSame('{"aps":{"alert":{"title":"Hi","body":"There"},"badge":3}}', $message->body());
        self::assertSame(Platform::Apns, $message->platform());
        self::assertSame(self::TOKEN, $message->target());
    }

    /**
     * @return iterable<string, array{PushType, string, string}>
     */
    public static function topics(): iterable
    {
        yield 'alert' => [PushType::Alert, 'com.example.app', '10'];
        yield 'background' => [PushType::Background, 'com.example.app', '5'];
        yield 'voip' => [PushType::Voip, 'com.example.app.voip', '10'];
        yield 'complication' => [PushType::Complication, 'com.example.app.complication', '10'];
        yield 'fileprovider' => [PushType::FileProvider, 'com.example.app.pushkit.fileprovider', '10'];
        yield 'liveactivity' => [PushType::LiveActivity, 'com.example.app.push-type.liveactivity', '10'];
        yield 'location' => [PushType::Location, 'com.example.app.location-query', '10'];
        yield 'pushtotalk' => [PushType::PushToTalk, 'com.example.app.voip-ptt', '10'];
        yield 'widgets' => [PushType::Widgets, 'com.example.app.push-type.widgets', '10'];
        yield 'controls' => [PushType::Controls, 'com.example.app.push-type.controls', '10'];
        yield 'accessory' => [PushType::Accessory, 'com.example.app.push-type.accessory', '10'];
    }

    #[DataProvider('topics')]
    public function test_each_push_type_derives_its_topic_and_priority(PushType $type, string $topic, string $priority): void
    {
        $headers = Message::of($type, self::TOKEN)->bundleId('com.example.app')->headers();

        self::assertSame($topic, $headers['apns-topic']);
        self::assertSame($type->value, $headers['apns-push-type']);
        self::assertSame($priority, $headers['apns-priority']);
    }

    public function test_mdm_uses_the_certificate_topic(): void
    {
        $headers = Message::mdm(self::TOKEN, 'com.apple.mgmt.External.abc')->headers();

        self::assertSame('com.apple.mgmt.External.abc', $headers['apns-topic']);
        self::assertSame('mdm', $headers['apns-push-type']);
    }

    public function test_explicit_topic_wins_over_bundle_id(): void
    {
        $headers = Message::voip(self::TOKEN)->bundleId('com.example.app')->topic('custom.topic')->headers();

        self::assertSame('custom.topic', $headers['apns-topic']);
    }

    public function test_background_defaults_to_content_available_and_priority_five(): void
    {
        $message = Message::background(self::TOKEN)->bundleId('com.example.app');
        $message->validate();

        self::assertSame('{"aps":{"content-available":1}}', $message->body());
        self::assertSame('5', $message->headers()['apns-priority']);
    }

    public function test_alert_with_content_available_stays_an_alert_at_priority_ten(): void
    {
        $message = Message::alert(self::TOKEN, Payload::create()->alert('Hello')->contentAvailable())->bundleId('com.example.app');
        $message->validate();

        self::assertSame('alert', $message->headers()['apns-push-type']);
        self::assertSame('10', $message->headers()['apns-priority']);
        self::assertSame('{"aps":{"alert":"Hello","content-available":1}}', $message->body());
    }

    public function test_background_refuses_priority_ten(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('priority 5 or 1');

        Message::background(self::TOKEN)->bundleId('com.example.app')->priority(Priority::Immediate)->validate();
    }

    public function test_background_refuses_an_alert(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('must not carry an alert');

        Message::background(self::TOKEN, Payload::create()->alert('x')->contentAvailable())->bundleId('com.example.app')->validate();
    }

    public function test_missing_topic_is_refused(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('no topic');

        Message::alert(self::TOKEN)->validate();
    }

    public function test_non_hex_token_is_refused(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('hexadecimal');

        Message::alert('not-a-token')->bundleId('com.example.app')->validate();
    }

    public function test_long_collapse_id_is_refused(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('64 bytes');

        Message::alert(self::TOKEN)->bundleId('com.example.app')->collapseId(str_repeat('x', 65))->validate();
    }

    public function test_non_canonical_id_is_refused(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('canonical UUID');

        Message::alert(self::TOKEN)->bundleId('com.example.app')->id('abc')->validate();
    }

    public function test_oversized_payload_is_refused(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('APNs allows 4096');

        Message::alert(self::TOKEN, Payload::create()->alert(str_repeat('a', 4100)))->bundleId('com.example.app')->validate();
    }

    public function test_voip_allows_five_kilobytes(): void
    {
        $message = Message::voip(self::TOKEN, Payload::create()->custom('blob', str_repeat('a', 4500)))->bundleId('com.example.app');
        $message->validate();

        self::assertGreaterThan(4096, strlen($message->body()));
    }

    public function test_live_activity_needs_event_and_timestamp(): void
    {
        $good = Message::liveActivity(self::TOKEN, Payload::create()->event('update')->timestamp(1700000000)->contentState(['score' => 1]))
            ->bundleId('com.example.app');
        $good->validate();
        self::assertSame('{"aps":{"event":"update","timestamp":1700000000,"content-state":{"score":1}}}', $good->body());

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('event() and timestamp()');
        Message::liveActivity(self::TOKEN, Payload::create()->event('update'))->bundleId('com.example.app')->validate();
    }

    public function test_expires_in_sets_a_future_timestamp(): void
    {
        $message = Message::alert(self::TOKEN)->bundleId('com.example.app')->expiresIn(60);

        self::assertNotNull($message->expiration());
        self::assertGreaterThanOrEqual(time() + 59, $message->expiration());
    }
}
