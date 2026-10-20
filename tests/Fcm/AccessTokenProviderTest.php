<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Tests\Fcm;

use Aporat\PushNotifications\Clock\FrozenClock;
use Aporat\PushNotifications\Exceptions\AuthenticationException;
use Aporat\PushNotifications\Exceptions\ConfigurationException;
use Aporat\PushNotifications\Fcm\AccessTokenProvider;
use Aporat\PushNotifications\Fcm\ServiceAccount;
use Aporat\PushNotifications\Tests\ArrayCachePool;
use Aporat\PushNotifications\Tests\Keys;
use Aporat\PushNotifications\Tests\MockHttp;
use PHPUnit\Framework\TestCase;

final class AccessTokenProviderTest extends TestCase
{
    private function account(): ServiceAccount
    {
        return ServiceAccount::fromArray([
            'type' => 'service_account',
            'project_id' => 'demo-project',
            'client_email' => 'fcm@demo-project.iam.gserviceaccount.com',
            'private_key' => Keys::rsa(),
            'private_key_id' => 'kid-1',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]);
    }

    public function test_exchanges_an_assertion_for_an_access_token(): void
    {
        $http = new MockHttp;
        $http->queue(200, ['access_token' => 'ya29.token', 'expires_in' => 3599, 'token_type' => 'Bearer']);
        $clock = new FrozenClock(1700000000);

        $provider = new AccessTokenProvider($this->account(), $http->client, $clock);

        self::assertSame('ya29.token', $provider->get());
        self::assertSame(1700003599, $provider->expiresAt());

        $request = $http->request();
        self::assertSame('https://oauth2.googleapis.com/token', (string) $request->getUri());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('content-type'));

        parse_str((string) $request->getBody(), $form);
        self::assertSame(AccessTokenProvider::GRANT_TYPE, $form['grant_type']);
        self::assertIsString($form['assertion']);

        $jwt = Keys::decodeJwt($form['assertion']);
        self::assertSame('RS256', $jwt['header']['alg']);
        self::assertSame('kid-1', $jwt['header']['kid']);
        self::assertSame([
            'iss' => 'fcm@demo-project.iam.gserviceaccount.com',
            'scope' => AccessTokenProvider::SCOPE,
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => 1700000000,
            'exp' => 1700003600,
        ], $jwt['claims']);
        self::assertSame(1, openssl_verify($jwt['input'], $jwt['signature'], Keys::publicKey(Keys::rsa()), OPENSSL_ALGO_SHA256));
    }

    public function test_token_is_reused_until_the_refresh_margin(): void
    {
        $http = new MockHttp;
        $http->queue(200, ['access_token' => 'one', 'expires_in' => 3600]);
        $http->queue(200, ['access_token' => 'two', 'expires_in' => 3600]);
        $clock = new FrozenClock(1700000000);

        $provider = new AccessTokenProvider($this->account(), $http->client, $clock, refreshMargin: 300);

        self::assertSame('one', $provider->get());
        $clock->advance(3299);
        self::assertSame('one', $provider->get());
        $clock->advance(1);
        self::assertSame('two', $provider->get());
        self::assertSame(2, $http->count());
    }

    public function test_token_is_shared_through_the_cache_pool(): void
    {
        $http = new MockHttp;
        $http->queue(200, ['access_token' => 'shared', 'expires_in' => 3600]);
        $clock = new FrozenClock(1700000000);
        $pool = new ArrayCachePool;

        (new AccessTokenProvider($this->account(), $http->client, $clock, $pool))->get();
        $other = new AccessTokenProvider($this->account(), $http->client, $clock, $pool);

        self::assertSame('shared', $other->get());
        self::assertSame(1700003600, $other->expiresAt());
        self::assertSame(1, $http->count());
    }

    public function test_google_refusal_is_an_authentication_exception(): void
    {
        $http = new MockHttp;
        $http->queue(400, ['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.']);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('invalid_grant: Invalid JWT Signature.');

        (new AccessTokenProvider($this->account(), $http->client, new FrozenClock))->get();
    }

    public function test_missing_access_token_is_an_authentication_exception(): void
    {
        $http = new MockHttp;
        $http->queue(200, ['token_type' => 'Bearer']);

        $this->expectException(AuthenticationException::class);

        (new AccessTokenProvider($this->account(), $http->client, new FrozenClock))->get();
    }

    public function test_service_account_from_json_and_file(): void
    {
        $json = (string) json_encode([
            'type' => 'service_account',
            'project_id' => 'p',
            'client_email' => 'e@p.iam.gserviceaccount.com',
            'private_key' => Keys::rsa(),
        ]);

        $account = ServiceAccount::fromJson($json);
        self::assertSame('p', $account->projectId);
        self::assertSame(ServiceAccount::DEFAULT_TOKEN_URI, $account->tokenUri);
        self::assertNull($account->privateKeyId);

        $path = tempnam(sys_get_temp_dir(), 'sa');
        self::assertNotFalse($path);
        file_put_contents($path, $json);

        try {
            self::assertSame($account->fingerprint(), ServiceAccount::fromFile($path)->fingerprint());
        } finally {
            unlink($path);
        }
    }

    public function test_service_account_rejects_the_wrong_key_type(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Expected a service_account key');

        ServiceAccount::fromArray(['type' => 'authorized_user', 'project_id' => 'p', 'client_email' => 'e', 'private_key' => Keys::rsa()]);
    }

    public function test_service_account_rejects_missing_fields(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('missing "client_email"');

        ServiceAccount::fromArray(['project_id' => 'p', 'private_key' => Keys::rsa()]);
    }

    public function test_service_account_rejects_invalid_json(): void
    {
        $this->expectException(ConfigurationException::class);

        ServiceAccount::fromJson('{');
    }
}
