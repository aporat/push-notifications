<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Tests\Fcm;

use Aporat\PushNotifications\Apns\Message as ApnsMessage;
use Aporat\PushNotifications\Clock\FrozenClock;
use Aporat\PushNotifications\Exceptions\AuthenticationException;
use Aporat\PushNotifications\Exceptions\InvalidMessageException;
use Aporat\PushNotifications\Fcm\AccessTokenProvider;
use Aporat\PushNotifications\Fcm\Client;
use Aporat\PushNotifications\Fcm\Message;
use Aporat\PushNotifications\Fcm\ServiceAccount;
use Aporat\PushNotifications\Outcome;
use Aporat\PushNotifications\Platform;
use Aporat\PushNotifications\Tests\Keys;
use Aporat\PushNotifications\Tests\MockHttp;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private MockHttp $http;

    private MockHttp $oauth;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->http = new MockHttp([], Client::BASE_URI);
        $this->oauth = new MockHttp;
        $this->oauth->queue(200, ['access_token' => 'ya29.first', 'expires_in' => 3600]);
        $this->oauth->queue(200, ['access_token' => 'ya29.second', 'expires_in' => 3600]);
        $this->clock = new FrozenClock(1700000000);
    }

    private function account(): ServiceAccount
    {
        return new ServiceAccount('demo-project', 'fcm@demo-project.iam.gserviceaccount.com', Keys::rsa());
    }

    private function client(): Client
    {
        $account = $this->account();

        return new Client(
            $account,
            [],
            $this->http->client,
            new AccessTokenProvider($account, $this->oauth->client, $this->clock),
            null,
            $this->clock,
        );
    }

    private function message(string $token = 'tok'): Message
    {
        return Message::to($token)->notification('Hello', 'World');
    }

    /**
     * @param  list<array<string, mixed>>  $details
     * @return array<string, mixed>
     */
    private function error(int $code, string $status, string $message, array $details = []): array
    {
        return ['error' => ['code' => $code, 'message' => $message, 'status' => $status, 'details' => $details]];
    }

    public function test_successful_send(): void
    {
        $this->http->queue(200, ['name' => 'projects/demo-project/messages/0:1']);

        $response = $this->client()->send($this->message());

        self::assertTrue($response->succeeded());
        self::assertSame('projects/demo-project/messages/0:1', $response->id);
        self::assertSame(Platform::Fcm, $response->platform);
        self::assertSame('tok', $response->target);

        $request = $this->http->request();
        self::assertSame('https://fcm.googleapis.com/v1/projects/demo-project/messages:send', (string) $request->getUri());
        self::assertSame('2.0', $request->getProtocolVersion());
        self::assertSame('Bearer ya29.first', $request->getHeaderLine('authorization'));
        self::assertSame('{"message":{"token":"tok","notification":{"title":"Hello","body":"World"}}}', (string) $request->getBody());
    }

    public function test_unregistered_token(): void
    {
        $this->http->queue(404, $this->error(404, 'NOT_FOUND', 'Requested entity was not found.', [
            ['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED'],
        ]));

        $response = $this->client()->send($this->message());

        self::assertSame(Outcome::Unregistered, $response->outcome);
        self::assertTrue($response->tokenIsUnregistered());
        self::assertSame('UNREGISTERED', $response->reason);
        self::assertSame('Requested entity was not found.', $response->message);
        self::assertSame(404, $response->statusCode);
    }

    public function test_not_found_without_details_is_still_unregistered(): void
    {
        $this->http->queue(404, $this->error(404, 'NOT_FOUND', 'gone'));

        self::assertSame(Outcome::Unregistered, $this->client()->send($this->message())->outcome);
    }

    public function test_malformed_token_is_an_invalid_target(): void
    {
        $this->http->queue(400, $this->error(400, 'INVALID_ARGUMENT', 'The registration token is not a valid FCM registration token', [
            ['@type' => 'type.googleapis.com/google.rpc.BadRequest', 'fieldViolations' => [['field' => 'message.token', 'description' => 'Invalid registration token']]],
            ['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'INVALID_ARGUMENT'],
        ]));

        $response = $this->client()->send($this->message());

        self::assertSame(Outcome::InvalidTarget, $response->outcome);
        self::assertSame('INVALID_ARGUMENT', $response->reason);
    }

    public function test_bad_field_is_an_invalid_message(): void
    {
        $this->http->queue(400, $this->error(400, 'INVALID_ARGUMENT', 'Invalid value at message.android.ttl', [
            ['@type' => 'type.googleapis.com/google.rpc.BadRequest', 'fieldViolations' => [['field' => 'message.android.ttl']]],
            ['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'INVALID_ARGUMENT'],
        ]));

        self::assertSame(Outcome::InvalidMessage, $this->client()->send($this->message())->outcome);
    }

    public function test_sender_id_mismatch(): void
    {
        $this->http->queue(403, $this->error(403, 'PERMISSION_DENIED', 'SenderId mismatch', [
            ['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'SENDER_ID_MISMATCH'],
        ]));

        $response = $this->client()->send($this->message());

        self::assertSame(Outcome::InvalidTarget, $response->outcome);
        self::assertFalse($response->isRetryable());
    }

    public function test_quota_exceeded_reads_retry_after(): void
    {
        $this->http->queue(429, $this->error(429, 'RESOURCE_EXHAUSTED', 'Quota exceeded', [
            ['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'QUOTA_EXCEEDED'],
        ]), ['retry-after' => '12']);

        $response = $this->client()->send($this->message());

        self::assertSame(Outcome::RateLimited, $response->outcome);
        self::assertTrue($response->isRetryable());
        self::assertSame(12, $response->retryAfter);
    }

    public function test_unavailable_is_retryable(): void
    {
        $this->http->queue(503, $this->error(503, 'UNAVAILABLE', 'try later'));

        self::assertTrue($this->client()->send($this->message())->isRetryable());
    }

    public function test_unauthorized_refreshes_the_token_and_retries_once(): void
    {
        $this->http->queue(401, $this->error(401, 'UNAUTHENTICATED', 'expired'));
        $this->http->queue(200, ['name' => 'projects/demo-project/messages/2']);

        $response = $this->client()->send($this->message());

        self::assertTrue($response->succeeded());
        self::assertSame(2, $this->http->count());
        self::assertSame('Bearer ya29.first', $this->http->request(0)->getHeaderLine('authorization'));
        self::assertSame('Bearer ya29.second', $this->http->request(1)->getHeaderLine('authorization'));
    }

    public function test_a_second_unauthorized_is_reported(): void
    {
        $this->http->queue(401, $this->error(401, 'UNAUTHENTICATED', 'expired'));
        $this->http->queue(401, $this->error(401, 'UNAUTHENTICATED', 'still expired'));

        $response = $this->client()->send($this->message());

        self::assertSame(Outcome::Unauthorized, $response->outcome);
        self::assertSame('UNAUTHENTICATED', $response->reason);
    }

    public function test_token_exchange_failure_throws(): void
    {
        $oauth = new MockHttp;
        $oauth->queue(400, ['error' => 'invalid_grant']);
        $account = $this->account();
        $client = new Client($account, [], $this->http->client, new AccessTokenProvider($account, $oauth->client, $this->clock));

        $this->expectException(AuthenticationException::class);

        $client->send($this->message());
    }

    public function test_connection_failure_becomes_a_response(): void
    {
        $this->http->queueFailure(new ConnectException('timed out', new Request('POST', '/')));

        $response = $this->client()->send($this->message());

        self::assertSame(Outcome::Unavailable, $response->outcome);
        self::assertSame(0, $response->statusCode);
        self::assertSame('timed out', $response->message);
    }

    public function test_send_many_keeps_keys_and_order(): void
    {
        $this->http->queue(200, ['name' => 'projects/demo-project/messages/a']);
        $this->http->queue(404, $this->error(404, 'NOT_FOUND', 'gone', [['errorCode' => 'UNREGISTERED']]));
        $this->http->queue(200, ['name' => 'projects/demo-project/messages/c']);

        $responses = $this->client()->sendMany(['x' => $this->message('a'), 'y' => $this->message('b'), 'z' => $this->message('c')]);

        self::assertSame(['x', 'y', 'z'], array_keys($responses));
        self::assertSame('projects/demo-project/messages/a', $responses['x']->id);
        self::assertSame(Outcome::Unregistered, $responses['y']->outcome);
        self::assertSame('c', $responses['z']->target);
    }

    public function test_rejects_an_apns_message(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('cannot send a apns message');

        $this->client()->send(ApnsMessage::alert('ab')->bundleId('x'));
    }
}
