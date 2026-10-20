<?php

declare(strict_types=1);

namespace Aporat\PushNotifications\Tests\Apns;

use Aporat\PushNotifications\Apns\Credentials;
use Aporat\PushNotifications\Apns\ProviderToken;
use Aporat\PushNotifications\Clock\FrozenClock;
use Aporat\PushNotifications\Exceptions\ConfigurationException;
use Aporat\PushNotifications\Tests\ArrayCachePool;
use Aporat\PushNotifications\Tests\Keys;
use PHPUnit\Framework\TestCase;

final class ProviderTokenTest extends TestCase
{
    private function credentials(): Credentials
    {
        return new Credentials('TEAM123456', 'KEY1234567', Keys::ec());
    }

    public function test_token_carries_apple_claims(): void
    {
        $clock = new FrozenClock(1700000000);
        $provider = new ProviderToken($this->credentials(), $clock);

        $decoded = Keys::decodeJwt($provider->get());

        self::assertSame('ES256', $decoded['header']['alg']);
        self::assertSame('KEY1234567', $decoded['header']['kid']);
        self::assertSame(['iss' => 'TEAM123456', 'iat' => 1700000000], $decoded['claims']);
        self::assertSame(1700000000, $provider->issuedAt());
    }

    public function test_token_is_reused_until_it_ages_out(): void
    {
        $clock = new FrozenClock(1700000000);
        $provider = new ProviderToken($this->credentials(), $clock, refreshAfter: 3000);

        $first = $provider->get();
        $clock->advance(2999);
        self::assertSame($first, $provider->get());

        $clock->advance(1);
        $second = $provider->get();
        self::assertNotSame($first, $second);
        self::assertSame(1700003000, Keys::decodeJwt($second)['claims']['iat']);
    }

    public function test_refresh_mints_a_new_token_immediately(): void
    {
        $clock = new FrozenClock(1700000000);
        $provider = new ProviderToken($this->credentials(), $clock);

        $first = $provider->get();
        $clock->advance(1);

        self::assertNotSame($first, $provider->refresh());
        self::assertSame(1700000001, $provider->issuedAt());
    }

    public function test_token_is_shared_through_the_cache_pool(): void
    {
        $clock = new FrozenClock(1700000000);
        $pool = new ArrayCachePool;

        $first = (new ProviderToken($this->credentials(), $clock, $pool))->get();
        $clock->advance(600);
        $other = new ProviderToken($this->credentials(), $clock, $pool);

        self::assertSame($first, $other->get());
        self::assertSame(1700000000, $other->issuedAt());
        self::assertSame(1, $pool->saves);
    }

    public function test_a_stale_cached_token_is_replaced(): void
    {
        $clock = new FrozenClock(1700000000);
        $pool = new ArrayCachePool;

        $first = (new ProviderToken($this->credentials(), $clock, $pool))->get();
        $clock->advance(3000);
        $second = (new ProviderToken($this->credentials(), $clock, $pool))->get();

        self::assertNotSame($first, $second);
        self::assertSame(2, $pool->saves);
    }

    public function test_different_keys_do_not_share_a_cache_entry(): void
    {
        $clock = new FrozenClock(1700000000);
        $pool = new ArrayCachePool;

        $a = (new ProviderToken(new Credentials('TEAM123456', 'KEYA', Keys::ec()), $clock, $pool))->get();
        $b = (new ProviderToken(new Credentials('TEAM123456', 'KEYB', Keys::ec()), $clock, $pool))->get();

        self::assertNotSame($a, $b);
    }

    public function test_refresh_interval_must_respect_apples_window(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('between 1200 and 3540');

        new ProviderToken($this->credentials(), new FrozenClock, refreshAfter: 60);
    }

    public function test_credentials_reject_empty_ids(): void
    {
        $this->expectException(ConfigurationException::class);

        new Credentials('', 'KEY', Keys::ec());
    }
}
