<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Tests\Apns;

use Aporat\PushNotifications\Apns\Client;
use Aporat\PushNotifications\Apns\Credentials;
use Aporat\PushNotifications\Apns\Environment;
use Aporat\PushNotifications\Apns\Message;
use Aporat\PushNotifications\Apns\Payload;
use Aporat\PushNotifications\Apns\ProviderToken;
use Aporat\PushNotifications\Clock\FrozenClock;
use Aporat\PushNotifications\Exceptions\ConfigurationException;
use Aporat\PushNotifications\Exceptions\InvalidMessageException;
use Aporat\PushNotifications\Fcm\Message as FcmMessage;
use Aporat\PushNotifications\Outcome;
use Aporat\PushNotifications\Platform;
use Aporat\PushNotifications\Response;
use Aporat\PushNotifications\Tests\Keys;
use Aporat\PushNotifications\Tests\MockHttp;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const string TOKEN = '00fc13adff785122b4ad28809a3420982341241421348097878e577c991de8f0';

    private MockHttp $http;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->http = new MockHttp([], Environment::Sandbox->baseUri());
        $this->clock = new FrozenClock(1700000000);
    }

    private function client(): Client
    {
        return new Client(
            new Credentials('TEAM123456', 'KEY1234567', Keys::ec()),
            Environment::Sandbox,
            [],
            $this->http->client,
            null,
            null,
            $this->clock,
        );
    }

    private function message(string $token = self::TOKEN): Message
    {
        return Message::alert($token, Payload::create()->alert('Hello'))->bundleId('com.example.app');
    }

    public function test_successful_push(): void
    {
        $this->http->queue(200, '', ['apns-id' => 'eabeae54-14a8-11e5-b60b-1697f925ec7b', 'apns-unique-id' => 'u-1']);

        $response = $this->client()->send($this->message());

        self::assertTrue($response->succeeded());
        self::assertSame(Outcome::Delivered, $response->outcome);
        self::assertSame(200, $response->statusCode);
        self::assertSame('eabeae54-14a8-11e5-b60b-1697f925ec7b', $response->id);
        self::assertSame('u-1', $response->headers['apns-unique-id']);
        self::assertNull($response->reason);
        self::assertSame(Platform::Apns, $response->platform);
        self::assertSame(self::TOKEN, $response->target);

        $request = $this->http->request();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.sandbox.push.apple.com/3/device/'.self::TOKEN, (string) $request->getUri());
        self::assertSame('2.0', $request->getProtocolVersion());
        self::assertSame('com.example.app', $request->getHeaderLine('apns-topic'));
        self::assertSame('alert', $request->getHeaderLine('apns-push-type'));
        self::assertSame('10', $request->getHeaderLine('apns-priority'));
        self::assertSame('application/json', $request->getHeaderLine('content-type'));
        self::assertSame('{"aps":{"alert":"Hello"}}', (string) $request->getBody());

        self::assertStringStartsWith('bearer ', $request->getHeaderLine('authorization'));
        $jwt = Keys::decodeJwt(substr($request->getHeaderLine('authorization'), 7));
        self::assertSame('KEY1234567', $jwt['header']['kid']);
        self::assertSame('TEAM123456', $jwt['claims']['iss']);
        self::assertSame(1700000000, $jwt['claims']['iat']);
    }

    public function test_unregistered_token(): void
    {
        $this->http->queue(410, ['reason' => 'Unregistered', 'timestamp' => 1700000000123], ['apns-id' => 'x']);

        $response = $this->client()->send($this->message());

        self::assertSame(Outcome::Unregistered, $response->outcome);
        self::assertTrue($response->tokenIsUnregistered());
        self::assertFalse($response->isRetryable());
        self::assertSame(410, $response->statusCode);
        self::assertSame('Unregistered', $response->reason);
        self::assertSame(1700000000123, $response->timestamp);
    }

    public function test_bad_device_token_is_an_invalid_target(): void
    {
        $this->http->queue(400, ['reason' => 'BadDeviceToken']);

        $response = $this->client()->send($this->message());

        self::assertSame(Outcome::InvalidTarget, $response->outcome);
        self::assertFalse($response->tokenIsUnregistered());
        self::assertSame('BadDeviceToken', $response->message);
    }

    public function test_unknown_reason_falls_back_on_status(): void
    {
        $this->http->queue(503, ['reason' => 'SomethingNew']);

        $response = $this->client()->send($this->message());

        self::assertSame(Outcome::Unavailable, $response->outcome);
        self::assertSame('SomethingNew', $response->reason);
    }

    public function test_expired_provider_token_is_refreshed_and_retried_once(): void
    {
        $this->http->queue(403, ['reason' => 'ExpiredProviderToken']);
        $this->http->queue(200, '', ['apns-id' => 'second']);

        $client = $this->client();
        $this->clock->advance(5);
        $response = $client->send($this->message());

        self::assertTrue($response->succeeded());
        self::assertSame('second', $response->id);
        self::assertSame(2, $this->http->count());

        $first = Keys::decodeJwt(substr($this->http->request(0)->getHeaderLine('authorization'), 7));
        $second = Keys::decodeJwt(substr($this->http->request(1)->getHeaderLine('authorization'), 7));
        self::assertSame(1700000005, $first['claims']['iat']);
        self::assertSame(1700000005, $second['claims']['iat']);
        self::assertNotSame($this->http->request(0)->getHeaderLine('authorization'), $this->http->request(1)->getHeaderLine('authorization'));
    }

    public function test_a_second_expired_provider_token_is_not_retried_again(): void
    {
        $this->http->queue(403, ['reason' => 'ExpiredProviderToken']);
        $this->http->queue(403, ['reason' => 'ExpiredProviderToken']);

        $response = $this->client()->send($this->message());

        self::assertSame(Outcome::Unauthorized, $response->outcome);
        self::assertSame(2, $this->http->count());
    }

    public function test_connection_failure_becomes_a_response(): void
    {
        $this->http->queueFailure(new ConnectException('Connection refused', new Request('POST', '/')));

        $response = $this->client()->send($this->message());

        self::assertSame(Outcome::Unavailable, $response->outcome);
        self::assertTrue($response->isRetryable());
        self::assertSame(0, $response->statusCode);
        self::assertSame('TransportFailure', $response->reason);
        self::assertSame('Connection refused', $response->message);
    }

    public function test_send_many_keeps_keys_and_order(): void
    {
        $this->http->queue(200, '', ['apns-id' => 'a']);
        $this->http->queue(410, ['reason' => 'Unregistered']);
        $this->http->queue(200, '', ['apns-id' => 'c']);

        $responses = $this->client()->sendMany([
            'first' => $this->message('aa'),
            'second' => $this->message('bb'),
            'third' => $this->message('cc'),
        ]);

        self::assertSame(['first', 'second', 'third'], array_keys($responses));
        self::assertSame('aa', $responses['first']->target);
        self::assertTrue($responses['first']->succeeded());
        self::assertSame(Outcome::Unregistered, $responses['second']->outcome);
        self::assertSame('c', $responses['third']->id);
        self::assertSame(3, $this->http->count());
    }

    public function test_send_many_validates_everything_before_sending_anything(): void
    {
        $this->http->queue(200);

        try {
            $this->client()->sendMany([$this->message(), Message::alert(self::TOKEN)]);
            self::fail('Expected an InvalidMessageException.');
        } catch (InvalidMessageException) {
            self::assertSame(0, $this->http->count());
        }
    }

    public function test_rejects_an_fcm_message(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('cannot send a fcm message');

        $this->client()->send(FcmMessage::to('t')->notification('x'));
    }

    public function test_with_environment_shares_the_provider_token(): void
    {
        $token = new ProviderToken(new Credentials('TEAM123456', 'KEY1234567', Keys::ec()), $this->clock);
        $production = new Client(new Credentials('TEAM123456', 'KEY1234567', Keys::ec()), Environment::Production, [], $this->http->client, $token, null, $this->clock);

        $sandbox = $production->withEnvironment(Environment::Sandbox);

        self::assertSame(Environment::Sandbox, $sandbox->environment());
        self::assertSame($production, $production->withEnvironment(Environment::Production));
        self::assertNotSame($production, $sandbox);
    }

    public function test_retry_after_is_read(): void
    {
        $this->http->queue(429, ['reason' => 'TooManyRequests'], ['retry-after' => '30']);

        $response = $this->client()->send($this->message());

        self::assertSame(Outcome::RateLimited, $response->outcome);
        self::assertSame(30, $response->retryAfter);
    }

    public function test_send_many_throws_when_the_key_cannot_sign(): void
    {
        $client = new Client(new Credentials('TEAM123456', 'KEY1234567', Keys::rsa()), Environment::Sandbox, [], $this->http->client, null, null, $this->clock);
        $this->http->queue(200);

        try {
            $client->sendMany(['a' => $this->message('aa'), 'b' => $this->message('bb')]);
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('not an EC key', $e->getMessage());
            self::assertSame(0, $this->http->count());
        }
    }

    public function test_connection_failure_message_drops_the_device_token_url(): void
    {
        $this->http->queueFailure(new ConnectException('cURL error 7: Failed to connect to api.push.apple.com port 443 (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://api.push.apple.com/3/device/'.self::TOKEN, new Request('POST', '/')));

        $response = $this->client()->send($this->message());

        self::assertSame('cURL error 7: Failed to connect to api.push.apple.com port 443', $response->message);
        self::assertStringNotContainsString(self::TOKEN, (string) $response->message);
    }

    public function test_concurrent_expired_tokens_refresh_once(): void
    {
        // Three requests go out with token T1 and all come back expired. The
        // first rejection mints T2; the other two see T1 is no longer current
        // and reuse T2 instead of minting T3 and T4.
        $token = new ProviderToken(new Credentials('TEAM123456', 'KEY1234567', Keys::ec()), $this->clock);
        $client = new Client(new Credentials('TEAM123456', 'KEY1234567', Keys::ec()), Environment::Sandbox, [], $this->http->client, $token, null, $this->clock);
        $messages = ['a' => $this->message('aa'), 'b' => $this->message('bb'), 'c' => $this->message('cc')];

        // All three go out before any answer arrives, then each retries:
        // a, b, c, then a', b', c'.
        $stale = $token->get();
        foreach ($messages as $message) {
            $this->http->queue(403, ['reason' => 'ExpiredProviderToken']);
        }
        foreach ($messages as $key => $message) {
            $this->http->queue(200, '', ['apns-id' => $key]);
        }

        $responses = $client->sendMany($messages);

        self::assertSame(['a', 'b', 'c'], array_map(static fn (Response $r): ?string => $r->id, array_values($responses)));
        self::assertSame(6, $this->http->count());
        $authorizations = array_unique(array_map(fn (int $i): string => $this->http->request($i)->getHeaderLine('authorization'), range(0, 5)));
        // T1 on the first request, T2 everywhere after: no third token.
        self::assertCount(2, $authorizations);
        self::assertSame('bearer '.$stale, $this->http->request(0)->getHeaderLine('authorization'));
    }
}
