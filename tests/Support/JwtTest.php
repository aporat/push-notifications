<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Tests\Support;

use Aporat\PushNotifications\Exceptions\ConfigurationException;
use Aporat\PushNotifications\Support\Jwt;
use Aporat\PushNotifications\Tests\Keys;
use PHPUnit\Framework\TestCase;

final class JwtTest extends TestCase
{
    public function test_es256_token_verifies_with_openssl(): void
    {
        $jwt = Jwt::es256(['kid' => 'ABC123DEFG'], ['iss' => 'TEAM123456', 'iat' => 1700000000], Keys::ec());
        $decoded = Keys::decodeJwt($jwt);

        self::assertSame(['typ' => 'JWT', 'alg' => 'ES256', 'kid' => 'ABC123DEFG'], $decoded['header']);
        self::assertSame(['iss' => 'TEAM123456', 'iat' => 1700000000], $decoded['claims']);
        self::assertSame(64, strlen($decoded['signature']));
        self::assertSame(1, openssl_verify($decoded['input'], Keys::rawToDer($decoded['signature']), Keys::publicKey(Keys::ec()), OPENSSL_ALGO_SHA256));
    }

    public function test_es256_signatures_are_never_malleable_in_width(): void
    {
        // Sign a few times: every raw signature is exactly 64 bytes, whatever
        // the leading bytes of r and s turned out to be.
        for ($i = 0; $i < 10; $i++) {
            $decoded = Keys::decodeJwt(Jwt::es256([], ['n' => $i], Keys::ec()));
            self::assertSame(64, strlen($decoded['signature']));
        }
    }

    public function test_rs256_token_verifies_with_openssl(): void
    {
        $jwt = Jwt::rs256(['kid' => 'k1'], ['iss' => 'svc@example.iam.gserviceaccount.com', 'scope' => 'x'], Keys::rsa());
        $decoded = Keys::decodeJwt($jwt);

        self::assertSame('RS256', $decoded['header']['alg']);
        self::assertSame('k1', $decoded['header']['kid']);
        self::assertSame(1, openssl_verify($decoded['input'], $decoded['signature'], Keys::publicKey(Keys::rsa()), OPENSSL_ALGO_SHA256));
    }

    public function test_es256_rejects_an_rsa_key(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('not an EC key');

        Jwt::es256([], [], Keys::rsa());
    }

    public function test_rs256_rejects_an_ec_key(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('not an RSA key');

        Jwt::rs256([], [], Keys::ec());
    }

    public function test_garbage_key_is_reported(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('could not be read');

        Jwt::es256([], [], 'not a key');
    }
}
