<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Tests\Apns;

use Aporat\PushNotifications\Apns\Alert;
use Aporat\PushNotifications\Apns\InterruptionLevel;
use Aporat\PushNotifications\Apns\Payload;
use Aporat\PushNotifications\Apns\Sound;
use Aporat\PushNotifications\Exceptions\InvalidMessageException;
use PHPUnit\Framework\TestCase;

final class PayloadTest extends TestCase
{
    public function test_empty_payload_renders_an_empty_aps_object(): void
    {
        self::assertSame('{"aps":{}}', Payload::create()->toJson());
    }

    public function test_every_aps_key(): void
    {
        $json = Payload::create()
            ->alert(Alert::create('T', 'B')->subtitle('S')->launchImage('img')->titleLocalized('TK', ['a'])->bodyLocalized('BK'))
            ->badge(2)
            ->sound(Sound::critical('siren.caf', 0.8))
            ->threadId('t')
            ->category('c')
            ->contentAvailable()
            ->mutableContent()
            ->targetContentId('tc')
            ->interruptionLevel(InterruptionLevel::TimeSensitive)
            ->relevanceScore(1.5)
            ->filterCriteria('f')
            ->custom('deep', ['x' => 1])
            ->toArray();

        self::assertSame([
            'alert' => ['title' => 'T', 'subtitle' => 'S', 'body' => 'B', 'launch-image' => 'img', 'title-loc-key' => 'TK', 'title-loc-args' => ['a'], 'loc-key' => 'BK'],
            'badge' => 2,
            'sound' => ['name' => 'siren.caf', 'critical' => 1, 'volume' => 0.8],
            'thread-id' => 't',
            'category' => 'c',
            'content-available' => 1,
            'mutable-content' => 1,
            'target-content-id' => 'tc',
            'interruption-level' => 'time-sensitive',
            'relevance-score' => 1.0,
            'filter-criteria' => 'f',
        ], (array) $json['aps']);
        self::assertSame(['x' => 1], $json['deep']);
    }

    public function test_null_clears_a_key(): void
    {
        $payload = Payload::create()->badge(1)->badge(null)->sound('default')->sound(null)->contentAvailable()->contentAvailable(false);

        self::assertSame('{"aps":{}}', $payload->toJson());
    }

    public function test_empty_alert_is_dropped(): void
    {
        self::assertSame('{"aps":{}}', Payload::create()->alert(Alert::create())->toJson());
        self::assertFalse(Payload::create()->alert(Alert::create())->hasAlert());
    }

    public function test_custom_key_cannot_be_aps(): void
    {
        $this->expectException(InvalidMessageException::class);

        Payload::create()->custom('aps', []);
    }

    public function test_unicode_is_not_escaped(): void
    {
        self::assertSame('{"aps":{"alert":"héllo/там"}}', Payload::create()->alert('héllo/там')->toJson());
    }

    public function test_live_activity_start(): void
    {
        $json = Payload::create()
            ->event('start')
            ->timestamp(1700000000)
            ->attributes('DeliveryAttributes', ['order' => 7])
            ->contentState(['eta' => 12])
            ->staleDate(1700003600)
            ->dismissalDate(1700007200)
            ->alert(Alert::create('Order placed'))
            ->toArray();

        self::assertSame([
            'event' => 'start',
            'timestamp' => 1700000000,
            'attributes-type' => 'DeliveryAttributes',
            'attributes' => ['order' => 7],
            'content-state' => ['eta' => 12],
            'stale-date' => 1700003600,
            'dismissal-date' => 1700007200,
            'alert' => ['title' => 'Order placed'],
        ], (array) $json['aps']);
    }
}
